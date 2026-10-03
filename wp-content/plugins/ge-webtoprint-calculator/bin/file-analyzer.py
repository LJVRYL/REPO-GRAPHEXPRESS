#!/usr/bin/env python3
"""Deterministic, bounded metadata extraction. Run only inside the isolated CLI worker."""
import hashlib
import json
import re
import subprocess
import sys
import os
import time
import tempfile
import resource
import warnings
from datetime import datetime, timezone
from pathlib import Path

MAX_BYTES = 262144000
TIMEOUT = 12
OUTPUT_LIMIT = 262144
STARTED = time.monotonic()


def limits():
    resource.setrlimit(resource.RLIMIT_CPU, (12, 12))
    resource.setrlimit(resource.RLIMIT_AS, (768 * 1024 * 1024, 768 * 1024 * 1024))
    resource.setrlimit(resource.RLIMIT_FSIZE, (OUTPUT_LIMIT, OUTPUT_LIMIT))
    resource.setrlimit(resource.RLIMIT_CORE, (0, 0))


def run(argv):
    try:
        remaining = min(TIMEOUT, max(0.1, 40 - (time.monotonic() - STARTED)))
        with tempfile.TemporaryFile() as stdout, tempfile.TemporaryFile() as stderr:
            completed = subprocess.run(argv, stdin=subprocess.DEVNULL, stdout=stdout,
                                       stderr=stderr, timeout=remaining, check=False,
                                       env={"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "LC_ALL": "C"},
                                       close_fds=True, preexec_fn=limits)
            stdout.seek(0); stderr.seek(0)
            out = stdout.read(OUTPUT_LIMIT + 1); err = stderr.read(2048)
            if len(out) >= OUTPUT_LIMIT:
                return None, "", "output_limit"
            return completed.returncode, out.decode("utf-8", "replace"), err.decode("utf-8", "replace")
    except (OSError, subprocess.TimeoutExpired):
        return None, "", "tool_unavailable_or_timeout"


def digest(path):
    value = hashlib.sha256()
    with open(path, "rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            value.update(chunk)
    return value.hexdigest()


def pdf_info(path, result):
    info_code, info_out, _ = run(["pdfinfo", "-box", str(path)])
    preliminary = dict((k.strip(), v.strip()) for k, v in re.findall(r"^([^:\n]+):\s*(.*)$", info_out, re.M))
    if preliminary.get("Encrypted", "").lower().startswith("yes"):
        result["encrypted"] = True
        result["blockers"].append("pdf_encrypted")
        return
    encrypted_code, _, encrypted_err = run(["qpdf", "--is-encrypted", str(path)])
    if encrypted_code == 0:
        result["encrypted"] = True
        result["blockers"].append("pdf_encrypted")
        return
    code, out, err = run(["qpdf", "--check", str(path)])
    result["raw_tool_versions"]["qpdf"] = run(["qpdf", "--version"])[1].splitlines()[:1]
    if code is None:
        result["unverified"].append("pdf_structure")
    elif "invalid password" in (out + err).lower():
        result["encrypted"] = True
        result["blockers"].append("pdf_password_required")
        return
    elif code == 3:
        result["warnings"].append("pdf_structure_warning")
        result["pdf_structure_valid"] = True
    elif code != 0:
        result["blockers"].append("pdf_structure_invalid")
        return
    else:
        result["pdf_structure_valid"] = True
    code, out = info_code, info_out
    result["raw_tool_versions"]["poppler"] = run(["pdfinfo", "-v"])[2].splitlines()[:1]
    if code != 0:
        result["unverified"].append("pdf_page_metadata")
        return
    fields = dict((k.strip(), v.strip()) for k, v in re.findall(r"^([^:\n]+):\s*(.*)$", out, re.M))
    result["page_count"] = int(fields["Pages"]) if fields.get("Pages", "").isdigit() else None
    result["pdf_version"] = fields.get("PDF version")
    result["encrypted"] = fields.get("Encrypted", "").lower().startswith("yes")
    if result["encrypted"]:
        result["blockers"].append("pdf_encrypted")
    if result["page_count"]:
        box_code, box_out, _ = run(["pdfinfo", "-f", "1", "-l", str(min(result["page_count"], 100)), "-box", str(path)])
        if box_code == 0:
            out = box_out
        else:
            result["unverified"].append("page_boxes")
    # Poppler reports effective boxes, including defaults; this does not prove an explicit BleedBox.
    result["boxes"] = [{"page": int(p), "kind": k, "points": [float(x) for x in c.split()]}
                       for p, k, c in re.findall(r"^Page\s+(\d+)\s+(MediaBox|CropBox|TrimBox|BleedBox):\s+([-\d.]+\s+[-\d.]+\s+[-\d.]+\s+[-\d.]+)", out, re.M)]
    media = [b for b in result["boxes"] if b["kind"] == "MediaBox"]
    if media:
        first = media[0]["points"]
        result["page_size_mm"] = [round((first[2] - first[0]) * 25.4 / 72, 2), round((first[3] - first[1]) * 25.4 / 72, 2)]
        result["orientation"] = "square" if abs(result["page_size_mm"][0] - result["page_size_mm"][1]) < 0.5 else ("landscape" if result["page_size_mm"][0] > result["page_size_mm"][1] else "portrait")
        result["multiple_page_sizes"] = any(abs((b["points"][2] - b["points"][0]) - (first[2] - first[0])) > 0.5 or abs((b["points"][3] - b["points"][1]) - (first[3] - first[1])) > 0.5 for b in media)
        if result["page_count"] and len(media) < result["page_count"]:
            result["multiple_page_sizes"] = None
            result["unverified"].append("multiple_page_sizes")
    else:
        result["unverified"].append("page_boxes")
    code, out, _ = run(["pdffonts", str(path)])
    if code == 0:
        lines = out.splitlines()[2:]
        result["fonts_count"] = len(lines)
        result["fonts"] = []
        for line in lines[:200]:
            match = re.search(r"\s+(yes|no)\s+(yes|no)\s+(yes|no)\s+\d+\s+\d+\s*$", line)
            if match:
                result["fonts"].append({"name": line.split()[0][:160], "embedded": match[1] == "yes", "subset": match[2] == "yes"})
        result["fonts_unembedded"] = sum(1 for font in result["fonts"] if not font["embedded"])
        result["fonts_embedded"] = result["fonts_unembedded"] == 0
        if len(lines) > 200 or len(lines) != len(result["fonts"]):
            result["fonts_embedded"] = None
            result["unverified"].append("fonts_inventory_truncated")
    else:
        result["unverified"].append("fonts_embedded")
    code, out, _ = run(["pdfimages", "-list", str(path)])
    if code == 0:
        lines = out.splitlines()[2:]
        result["images_count"] = len(lines)
        ppis = []
        result["images"] = []
        for line in lines:
            parts = line.split()
            if len(parts) >= 15:
                try:
                    ppis.append(min(int(parts[12]), int(parts[13])))
                    if len(result["images"]) < 200:
                        result["images"].append({"page": int(parts[0]), "type": parts[2], "pixels": [int(parts[3]), int(parts[4])], "colorspace": parts[5], "effective_dpi": [int(parts[12]), int(parts[13])]})
                except ValueError:
                    pass
        result["image_resolution_min_ppi"] = min(ppis) if ppis else None
    else:
        result["unverified"].append("image_resolution")
    result["unverified"].extend(["colorspaces", "icc_profiles", "spot_colors", "transparency", "overprint", "explicit_bleed_box", "actual_bleed", "annotations_forms_layers"])


def raster_info(path, result):
    from PIL import Image, __version__
    result["raw_tool_versions"]["pillow"] = [__version__]
    Image.MAX_IMAGE_PIXELS = 100_000_000
    warnings.simplefilter("error", Image.DecompressionBombWarning)
    try:
        with Image.open(path, formats=["JPEG", "PNG", "TIFF", "WEBP"]) as im:
            result["pixel_dimensions"] = list(im.size)
            result["orientation"] = "square" if im.width == im.height else ("landscape" if im.width > im.height else "portrait")
            dpi = im.info.get("dpi")
            result["dpi_metadata"] = [round(float(d), 2) for d in dpi[:2]] if dpi else None
            result["physical_dimensions_mm"] = [round(im.width*25.4/dpi[0],2),round(im.height*25.4/dpi[1],2)] if dpi and min(dpi)>0 else None
            result["colorspaces"] = [im.mode]
            result["icc_profile_present"] = bool(im.info.get("icc_profile"))
            result["alpha_present"] = "A" in im.getbands() or "transparency" in im.info
            result["transparency"] = result["alpha_present"]
            result["compression"] = im.info.get("compression")
            result["page_count"] = getattr(im, "n_frames", 1)
            result["metadata"] = {"exif_orientation": im.getexif().get(274)}
    except (OSError, ValueError, Image.DecompressionBombError, Image.DecompressionBombWarning):
        result["blockers"].append("raster_invalid_or_pixel_limit")


def pdf_resources(path, result):
    """Inspect resource dictionaries and operators; never execute forms/actions/JS."""
    try:
        import pikepdf
    except ImportError:
        result["unverified"].append("pdf_resource_reader_unavailable")
        return
    result["raw_tool_versions"]["pikepdf"] = [pikepdf.__version__]
    colors, spots, intents = set(), set(), []
    transparency, overprint, incomplete = False, False, False
    visited = set()

    def scan_resources(resources, depth=0):
        nonlocal transparency, overprint, incomplete
        if depth > 12 or len(visited) > 2000:
            incomplete = True
            return
        for space in resources.get('/ColorSpace', {}).values():
            try:
                if isinstance(space, pikepdf.Array):
                    kind = str(space[0]); colors.add(kind.lstrip('/'))
                    if kind == '/Separation': spots.add(str(space[1]).lstrip('/')[:160])
                    elif kind == '/DeviceN': spots.update(str(x).lstrip('/')[:160] for x in space[1])
                else: colors.add(str(space).lstrip('/')[:80])
            except (TypeError, ValueError, IndexError): incomplete = True
        for state in resources.get('/ExtGState', {}).values():
            transparency |= float(state.get('/ca', 1)) < 1 or float(state.get('/CA', 1)) < 1 or '/SMask' in state
            overprint |= bool(state.get('/OP', False)) or bool(state.get('/op', False))
        for obj in resources.get('/XObject', {}).values():
            key = obj.objgen
            if key in visited: continue
            visited.add(key)
            if '/SMask' in obj or '/Mask' in obj: transparency = True
            color = obj.get('/ColorSpace')
            if isinstance(color, pikepdf.Name): colors.add(str(color).lstrip('/'))
            elif isinstance(color, pikepdf.Array): colors.add(str(color[0]).lstrip('/'))
            if obj.get('/Subtype') == '/Form':
                scan_resources(obj.get('/Resources', {}), depth+1)
                scan_content(obj)

    def scan_content(obj):
        nonlocal incomplete
        for i, instruction in enumerate(pikepdf.parse_content_stream(obj)):
            if i > 100000 or time.monotonic()-STARTED > 35:
                incomplete = True
                return
            op = str(instruction.operator)
            if op in ('rg','RG'): colors.add('DeviceRGB')
            elif op in ('k','K'): colors.add('DeviceCMYK')
            elif op in ('g','G'): colors.add('DeviceGray')

    try:
        with pikepdf.open(path) as pdf:
            for index, page in enumerate(pdf.pages):
                if index >= 100:
                    incomplete = True
                    break
                scan_resources(page.obj.get('/Resources', {}))
                scan_content(page)
                if page.obj.get('/Group', {}).get('/S') == '/Transparency': transparency = True
                for box in ('MediaBox','CropBox','TrimBox','BleedBox'):
                    if '/'+box in page.obj:
                        result.setdefault('explicit_boxes', []).append({'page': index+1,'kind':box,'points':[float(x) for x in page.obj['/'+box]]})
            for intent in pdf.Root.get('/OutputIntents', []):
                intents.append({'identifier':str(intent.get('/OutputConditionIdentifier',''))[:160], 'profile_present':'/DestOutputProfile' in intent})
            result['colorspaces'] = sorted(colors)
            result['spot_colors'] = sorted(spots)
            result['transparency'] = transparency
            result['overprint'] = 'detected' if overprint else 'unverified'
            result['icc_profiles'] = intents
            result['color_inspection_complete'] = not incomplete
            result['unverified'] = [x for x in result['unverified'] if x not in ('colorspaces','icc_profiles','spot_colors','transparency','explicit_bleed_box')]
            # The inventories describe detected operators/resources, not rendered color coverage.
            result['unverified'].extend(['rendered_color_coverage','actual_bleed'])
            if incomplete: result['unverified'].append('pdf_resource_inventory_truncated')
    except (pikepdf.PdfError, TypeError, ValueError, OverflowError, RuntimeError, MemoryError):
        result['unverified'].append('pdf_resource_inspection_failed')


def main():
    limits()
    if len(sys.argv) not in (3, 4):
        raise SystemExit(2)
    if sys.argv[1] == "--health":
        versions={"python":[sys.version.split()[0]],"poppler":run(["pdfinfo","-v"])[2].splitlines()[:1],"qpdf":run(["qpdf","--version"])[1].splitlines()[:1],"exiftool":run(["exiftool","-ver"])[1].splitlines()[:1]}
        try:
            import PIL, pikepdf
            versions.update({"pillow":[PIL.__version__],"pikepdf":[pikepdf.__version__]})
        except ImportError:
            versions.update({"pillow":[],"pikepdf":[]})
        print(json.dumps({"schema_version":1,"checksum_sha256":"health","runtime_healthy":all(versions.values()),"raw_tool_versions":versions,"status":"analyzed"}))
        return
    path = Path(sys.argv[1])
    mime = sys.argv[2]
    mode = sys.argv[3] if len(sys.argv) == 4 else "technical"
    size = path.stat().st_size
    result = {"schema_version": 1, "analyzer_version": "1.1.0", "mime_type": mime,
              "bytes": size, "checksum_sha256": digest(path) if size <= MAX_BYTES else None,
              "page_count": None, "page_size_mm": None, "pdf_structure_valid": None,
              "encrypted": None, "fonts_embedded": None, "warnings": [], "blockers": [],
              "unverified": [], "raw_tool_versions": {}, "detected_format": "unknown", "mode": mode,
              "page_sizes": [], "boxes": [], "fonts": None, "colorspaces": None, "spot_colors": None,
              "images_count": None, "images": None, "transparency": None, "overprint": "unverified",
              "icc_profiles": None, "metadata": {}, "duration_ms": None, "analyzed_at": None}
    with open(path, "rb") as stream:
        magic = stream.read(16)
    signatures = {"application/pdf": magic.startswith(b"%PDF-"),
                  "image/jpeg": magic.startswith(b"\xff\xd8\xff"),
                  "image/png": magic.startswith(b"\x89PNG\r\n\x1a\n"),
                  "image/tiff": magic.startswith((b"II*\x00", b"MM\x00*"))}
    detected = next((m for m, valid in signatures.items() if valid), None)
    result["detected_format"] = {"application/pdf":"PDF","image/jpeg":"JPEG","image/png":"PNG","image/tiff":"TIFF"}.get(detected,"unknown")
    result["raw_tool_versions"] = {"python": [sys.version.split()[0]], "poppler": run(["pdfinfo","-v"])[2].splitlines()[:1], "qpdf": run(["qpdf","--version"])[1].splitlines()[:1], "exiftool": run(["exiftool","-ver"])[1].splitlines()[:1]}
    if size > MAX_BYTES:
        result["blockers"].append("analysis_size_limit")
    elif mime in signatures and not signatures[mime]:
        result["blockers"].append("mime_content_mismatch")
    elif mode == "basic":
        result["unverified"].append("administrative_metadata_only")
    elif detected == "application/pdf":
        result["mime_type"] = detected
        pdf_info(path, result)
        if not result['blockers']:
            pdf_resources(path, result)
    elif detected in ("image/jpeg", "image/png", "image/tiff"):
        result["mime_type"] = detected
        raster_info(path, result)
    else:
        result["unverified"].append("unsupported_for_deep_preflight")
        result["status"] = "unsupported"
    media = [b for b in result["boxes"] if b["kind"] == "MediaBox"]
    result["page_sizes"] = [{"page": b["page"], "mm": [round((b["points"][2]-b["points"][0])*25.4/72,2),round((b["points"][3]-b["points"][1])*25.4/72,2)]} for b in media]
    result['unverified'] = list(dict.fromkeys(result['unverified']))
    result['sha256'] = result['checksum_sha256']
    result['file_size'] = result['bytes']
    result['password_status'] = 'encrypted' if result['encrypted'] else ('not_encrypted' if result['encrypted'] is False else 'unverified')
    result["status"] = "blocker" if result["blockers"] else result.get("status", "warning" if result["warnings"] else "analyzed")
    result["duration_ms"] = round((time.monotonic() - STARTED)*1000)
    result["analyzed_at"] = datetime.now(timezone.utc).isoformat()
    print(json.dumps(result, ensure_ascii=True, separators=(",", ":")))


if __name__ == "__main__":
    main()
