const BASE_PATH = '/mantenimientoftth';

const adminStatus = document.getElementById('admin-status');
const reloadBtn = document.getElementById('reload-btn');
const tableBody = document.getElementById('admin-table-body');
const adminKeyInput = document.getElementById('admin-key');

function getKeyFromUrl() {
  const params = new URLSearchParams(window.location.search);
  return params.get('key') || '';
}

function setStatus(message, type) {
  adminStatus.textContent = message || '';
  adminStatus.className = type || '';
}

function buildUrl(path) {
  const key = adminKeyInput.value.trim();
  const url = new URL(path, window.location.origin);
  if (key) url.searchParams.set('key', key);
  return url.toString();
}

async function fetchSubmissions() {
  const url = buildUrl(`${BASE_PATH}/api/admin/submissions`);
  setStatus('Cargando...', '');
  try {
    const res = await fetch(url);
    if (!res.ok) {
      const text = await res.text();
      throw new Error(text || 'No autorizado');
    }
    const data = await res.json();
    renderTable(data);
    setStatus(`Total: ${data.length}`, '');
  } catch (err) {
    console.error(err);
    renderTable([]);
    setStatus(err.message || 'Error al cargar', 'error');
  }
}

function renderTable(rows) {
  if (!rows || rows.length === 0) {
    tableBody.innerHTML = '<tr><td colspan="7" class="muted">Sin datos</td></tr>';
    return;
  }

  const key = adminKeyInput.value.trim();
  tableBody.innerHTML = rows
    .map((row) => {
      const photoUrl = `${BASE_PATH}/api/admin/submissions/${row.id}/photo?key=${encodeURIComponent(key)}`;
      const qrUrl = `${BASE_PATH}/api/admin/submissions/${row.id}/qr?key=${encodeURIComponent(key)}`;

      return `
        <tr>
          <td>Tarjeta #${row.id}</td>
          <td>${row.fullName || ''}</td>
          <td>${row.role || ''}</td>
          <td>${row.dni || ''}</td>
          <td>${row.createdAt || ''}</td>
          <td><a href="${photoUrl}" class="link" target="_blank" rel="noreferrer">Abrir</a><br><img src="${photoUrl}" alt="Foto ${row.id}" class="thumb" loading="lazy" /></td>
          <td><a href="${qrUrl}" class="link" target="_blank" rel="noreferrer">Abrir</a><br><img src="${qrUrl}" alt="QR ${row.id}" class="thumb" loading="lazy" /></td>
        </tr>
      `;
    })
    .join('');
}

reloadBtn.addEventListener('click', fetchSubmissions);

window.addEventListener('DOMContentLoaded', () => {
  const key = getKeyFromUrl();
  adminKeyInput.value = key;
  if (!key) {
    setStatus('Falta ?key=... en la URL', 'error');
    return;
  }
  fetchSubmissions();
});
