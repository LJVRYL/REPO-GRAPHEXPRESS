#!/usr/bin/env python3
"""Deterministic extraction only. Files are data, never instructions or formulas."""
import csv, io, json, re, sys, zipfile, subprocess, xml.etree.ElementTree as ET
from pathlib import Path
sys.path.insert(0,'/opt/ge-cost-engine/python')

SHEETS=[]

def extract(path,selected=""):
    global SHEETS
    ext=path.suffix.lower()
    if path.stat().st_size>20*1024*1024: raise ValueError('Archivo demasiado grande')
    if ext=='.csv':
        raw=path.read_bytes()
        try: text=raw.decode('utf-8-sig')
        except UnicodeDecodeError: text=raw.decode('cp1252')
        try: dialect=csv.Sniffer().sniff(text[:10000],delimiters=',;\t')
        except csv.Error: dialect=csv.excel
        return list(csv.reader(io.StringIO(text),dialect)),[], 'csv'
    if ext=='.xlsx':
        ns={'s':'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
        with zipfile.ZipFile(path) as z:
            if sum(x.file_size for x in z.infolist())>50*1024*1024: raise ValueError('Planilla descomprimida demasiado grande')
            strings=[]
            if 'xl/sharedStrings.xml' in z.namelist():
                strings=[''.join(t.text or '' for t in n.findall('.//s:t',ns)) for n in ET.fromstring(z.read('xl/sharedStrings.xml')).findall('s:si',ns)]
            sheets=[x for x in z.namelist() if re.fullmatch(r'xl/worksheets/sheet\d+\.xml',x)]
            names={x:Path(x).stem for x in sheets}
            if 'xl/workbook.xml' in z.namelist() and 'xl/_rels/workbook.xml.rels' in z.namelist():
                rels={r.get('Id'):r.get('Target','') for r in ET.fromstring(z.read('xl/_rels/workbook.xml.rels'))}
                for sheet in ET.fromstring(z.read('xl/workbook.xml')).findall('.//s:sheet',ns):
                    target=rels.get(sheet.get('{http://schemas.openxmlformats.org/officeDocument/2006/relationships}id'),'')
                    target=target.lstrip('/') if target.startswith('/') else 'xl/'+target
                    if target in names:names[target]=sheet.get('name',Path(target).stem)
            SHEETS=list(names.values())
            if selected:
                chosen=[x for x in sheets if names[x]==selected]
                if not chosen:raise ValueError('Hoja inexistente')
                sheets=chosen
            if len(sheets)!=1:return [],['Elegí la hoja que querés interpretar. Cada hoja se revisa como una lista independiente.'],'xlsx'

            rows=[]
            for row in ET.fromstring(z.read(sheets[0])).findall('.//s:sheetData/s:row',ns):
                values=[]
                for c in row.findall('s:c',ns):
                    if c.find('s:f',ns) is not None: raise ValueError('La planilla contiene fórmulas; exportá valores verificados a CSV.')
                    ref=re.match(r'([A-Z]+)',c.get('r','A')).group(1); col=0
                    for letter in ref: col=col*26+ord(letter)-64
                    if col>100: raise ValueError('Demasiadas columnas')
                    while len(values)<col: values.append('')
                    v=c.find('s:v',ns); value=v.text if v is not None else ''
                    if c.get('t')=='s': value=strings[int(value)]
                    elif c.get('t')=='inlineStr': value=''.join(t.text or '' for t in c.findall('.//s:t',ns))
                    values[col-1]=value or ''
                rows.append(values)
            return rows,[], 'xlsx'
    if ext=='.xls':
        try: import xlrd
        except ImportError: return [],['Extractor XLS no disponible. Exportá a XLSX con valores o CSV.'],'xls'
        book=xlrd.open_workbook(path)
        SHEETS=book.sheet_names()
        if selected:sheet=book.sheet_by_name(selected)
        elif book.nsheets==1:sheet=book.sheet_by_index(0)
        else:return [],['Elegí la hoja que querés interpretar.'],'xls'
        return [[str(v) for v in sheet.row_values(i)] for i in range(sheet.nrows)],[], 'xls'
    if ext=='.pdf':
        info=subprocess.run(['pdfinfo',str(path)],capture_output=True,text=True,timeout=5,check=True).stdout
        pages=re.search(r'^Pages:\s+(\d+)',info,re.M)
        if not pages or int(pages.group(1))>100:raise ValueError('PDF excede 100 páginas o es inválido')
        text=subprocess.run(['pdftotext','-layout','-enc','UTF-8',str(path),'-'],capture_output=True,text=True,timeout=15,check=True).stdout
        rows=[re.split(r'\s{2,}',line.strip()) for line in text.splitlines() if line.strip() and len(re.split(r'\s{2,}',line.strip()))>1]
        return rows, ['Revisá columnas y encabezados extraídos del PDF.'] if rows else ['PDF sin tabla reconocible. Revisar texto y mapear manualmente.' if text.strip() else 'PDF escaneado: necesita extracción asistida/OCR.'], 'pdf',text[:80000]
    return [],['Imagen: necesita extracción asistida y revisión humana.'],'image'

try:
    result=extract(Path(sys.argv[1]),sys.argv[2] if len(sys.argv)>2 else ""); rows=result[0]
    if len(rows)>5000: raise ValueError('Máximo 5000 filas por lista')
    print(json.dumps({'sheets':SHEETS,'selected_sheet':sys.argv[2] if len(sys.argv)>2 else '', 'rows':rows,'warnings':result[1],'parser':result[2],'text':result[3] if len(result)>3 else ''},ensure_ascii=False))
except Exception as e:
    print(json.dumps({'rows':[],'warnings':[str(e)],'parser':'failed','text':''},ensure_ascii=False))
