tailwind.config = {
    darkMode: 'class'
}
// === Toasts ===
const tc = document.getElementById('toastContainer');
function showToast(msg, type = 'success', dur = 3000) {
    const c = { success: 'bg-green-600', error: 'bg-red-600', info: 'bg-blue-600' };
    const e = document.createElement('div');
    e.className = (c[type] || c.success) + " text-white px-4 py-2 rounded shadow-md opacity-0 transition-all duration-300";
    e.textContent = msg;
    tc.appendChild(e);
    setTimeout(() => e.style.opacity = 1, 50);
    setTimeout(() => {
        e.style.opacity = 0;
        setTimeout(() => e.remove(), 300);
    }, dur);
}

// === Tema ===
const toggle = document.getElementById('themeToggle');
function updateTheme() {
    if (localStorage.theme === 'dark') {
        document.documentElement.classList.add('dark');
        toggle.textContent = '☀️';
    } else {
        document.documentElement.classList.remove('dark');
        toggle.textContent = '🌙';
    }
}
if (toggle) {
    updateTheme();
    toggle.addEventListener('click', () => {
        localStorage.theme = localStorage.theme === 'dark' ? 'light' : 'dark';
        updateTheme();
    });
}

// === Hjälpfunktion för popup ===
function showModal(content) {
    const overlay = document.createElement('div');
    overlay.className = 'fixed inset-0 bg-black/40 flex items-center justify-center z-50';
    overlay.innerHTML = `
      <div class='bg-white dark:bg-gray-800 p-5 rounded shadow-lg max-w-lg w-full relative'>
          <button class='absolute top-2 right-3 text-gray-400 hover:text-gray-600' onclick='this.closest(".fixed").remove()'>✖</button>
          ${content}
      </div>`;
    document.body.appendChild(overlay);
}

// === Generera rapport ===
async function generateReport() {
    const fd = new FormData();
    fd.append('action', 'generate_full_report');
    const r = await fetch('api.php', { method: 'POST', body: fd });
    const j = await r.json();
    if (!j.success) return showToast('Kunde inte generera rapport', 'error');
    let html = `<h3 class='text-xl font-semibold mb-2'>📊 Rapport</h3>`;
    html += `<p><b>Genomsnittlig öppentid:</b> ${j.avgDays} dagar</p>`;
    html += `<p><b>Öppnade senaste ${j.period} dagarna:</b> ${j.openedCount} (${j.dailyRate}/dag)</p>`;
    html += `<p><b>Kvar i lager:</b> ${j.remaining}</p>`;
    html += `<p><b>Prognos:</b> ${j.daysLeft ? j.daysLeft + ' dagar kvar — beräknat slut ' + j.forecastDate : '-'} </p>`;
    if (j.bad.length) {
        html += `<hr class='my-2'><p><b>Felaktiga (ej ersatta):</b></p><ul class='list-disc list-inside text-sm'>`;
        j.bad.forEach(b => html += `<li>Bal #${b.bale_id} (${b.supplier}, ${b.delivery_date})</li>`);
        html += '</ul>';
    } else html += `<p class='text-sm text-gray-500 mt-2'>Inga felaktiga balar 🎉</p>`;
    showModal(html);
}
document.getElementById('reportBtn')?.addEventListener('click', generateReport);

// === Kostnadsprognos ===
async function showCostPrediction() {
    const fd = new FormData();
    fd.append('action', 'predict_costs');
    const r = await fetch('api.php', { method: 'POST', body: fd });
    const j = await r.json();
    if (!j.success) return showToast('Kunde inte hämta prognos', 'error');
    let html = `<h3 class='text-xl font-semibold mb-2'>💰 Kostnadsprognos (6 månader)</h3>`;
    html += `<p><b>Genomsnittligt pris per bal:</b> ${j.avg_price} kr</p>`;
    html += `<p><b>Förbrukningstakt:</b> ${j.daily_rate} balar/dag</p>`;
    html += `<p><b>Kvar i lager:</b> ${j.remaining} balar</p>`;
    html += `<hr class='my-2'><table class='w-full text-sm border border-gray-300 dark:border-gray-700'><thead class='bg-gray-200 dark:bg-gray-800'><tr><th class='p-2'>Månad</th><th class='p-2'>Förväntade balar</th><th class='p-2'>Beräknad kostnad</th></tr></thead><tbody>`;
    j.forecast.forEach(f => {
        html += `<tr class='border-t dark:border-gray-700'><td class='p-2'>${f.month}</td><td class='p-2'>${f.bales_used}</td><td class='p-2'>${f.estimated_cost} kr</td></tr>`;
    });
    html += '</tbody></table>';
    showModal(html);
}
document.getElementById('forecastBtn')?.addEventListener('click', showCostPrediction);

// === Lägg till leverans ===
document.getElementById('addDeliveryForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const f = new FormData(e.target);
    f.append('action', 'add_delivery');
    const r = await fetch('api.php', { method: 'POST', body: f });
    const j = await r.json();
    if (j.success) location.reload();
});

// === Uppdatera leverans (betald) ===
async function updateDelivery(i, p) {
    const f = new FormData();
    f.append('action', 'update_delivery');
    f.append('id', i);
    f.append('paid', p);
    await fetch('api.php', { method: 'POST', body: f });
    showToast('💾 Sparat');
}

// === Faktura ===
async function uploadInvoice(i) {
    const inp = document.createElement('input');
    inp.type = 'file';
    inp.accept = 'application/pdf';
    inp.onchange = async () => {
        const f = inp.files[0];
        if (!f) return;
        const fd = new FormData();
        fd.append('action', 'upload_invoice_file');
        fd.append('id', i);
        fd.append('invoice_file', f);
        const r = await fetch('api.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.success) location.reload();
    };
    inp.click();
}

async function deleteInvoice(i) {
    if (!confirm('Ta bort faktura?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_invoice_file');
    fd.append('id', i);
    await fetch('api.php', { method: 'POST', body: fd });
    showToast('🗑️ Faktura borttagen');
    location.reload();
}

// === Balar ===
async function setStatus(i, s) {
    const fd = new FormData();
    fd.append('action', 'update_bale_status');
    fd.append('id', i);
    fd.append('status', s);
    await fetch('api.php', { method: 'POST', body: fd });
    location.reload();
}

async function toggleFlag(i, f, v) {
    const fd = new FormData();
    fd.append('action', 'toggle_flag');
    fd.append('id', i);
    fd.append('flag', f);
    fd.append('value', v);
    await fetch('api.php', { method: 'POST', body: fd });
    location.reload();
}

async function uploadPhoto(id) {
    const i = document.createElement('input');
    i.type = 'file';
    i.accept = 'image/*';
    i.onchange = async () => {
        const f = i.files[0];
        if (!f) return;
        const fd = new FormData();
        fd.append('action', 'upload_photo');
        fd.append('id', id);
        fd.append('photo', f);
        const r = await fetch('api.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.success) {
            showToast('📷 Bild uppladdad');
            location.reload();
        }
    };
    i.click();
}

async function deletePhoto(id) {
    if (!confirm('Ta bort bilden för denna bal?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_photo');
    fd.append('id', id);
    const r = await fetch('api.php', { method: 'POST', body: fd });
    const j = await r.json();
    if (j.success) {
        showToast('🗑️ Bild borttagen');
        location.reload();
    } else {
        showToast('⚠️ Kunde inte ta bort bilden', 'error');
    }
}

// === Redigerbara datum ===
function makeDatesEditable() {
    document.querySelectorAll('.editable-date').forEach(el => {
        el.addEventListener('click', () => {
            if (el.dataset.locked === 'true' || el.querySelector('input')) return;
            const id = el.dataset.id, f = el.dataset.field;
            const cur = el.textContent.trim();
            const i = document.createElement('input');
            i.type = 'date';
            i.className = 'border rounded p-1 text-sm w-full dark:bg-gray-700';
            i.value = cur && cur !== '-' ? cur : new Date().toISOString().split('T')[0];
            el.innerHTML = '';
            el.appendChild(i);
            i.focus();
            i.addEventListener('change', save);
            i.addEventListener('blur', save);

            async function save() {
                const newVal = i.value.trim(), oldVal = cur.trim();
                if (!newVal || newVal === oldVal || newVal === '-') {
                    el.textContent = oldVal || '-';
                    return;
                }
                const fd = new FormData();
                fd.append('action', 'update_date');
                fd.append('id', id);
                fd.append('field', f);
                fd.append('value', newVal);
                const r = await fetch('api.php', { method: 'POST', body: fd });
                const j = await r.json();
                if (j.success) {
                    el.textContent = newVal;
                    showToast('📅 Datum sparat');
                } else {
                    el.textContent = oldVal || '-';
                    showToast('⚠️ Kunde inte spara datum', 'error');
                }
            }
        });
    });
}
document.addEventListener('DOMContentLoaded', makeDatesEditable);

// === Redigerbara nummer (pris, vikt) ===
function makeNumbersEditable() {
    document.querySelectorAll('.editable-num').forEach(el => {
        el.addEventListener('click', () => {
            if (el.querySelector('input')) return;
            const id = el.dataset.id, field = el.dataset.field;
            const current = el.textContent.trim();
            const inp = document.createElement('input');
            inp.type = 'number';
            inp.step = '0.01';
            inp.value = current.replace(',', '.');
            inp.className = 'border rounded p-1 text-sm w-24 dark:bg-gray-700';
            el.innerHTML = '';
            el.appendChild(inp);
            inp.focus();
            inp.addEventListener('blur', save);
            inp.addEventListener('change', save);

            async function save() {
                const val = inp.value.trim();
                if (!val || val === current) { el.textContent = current; return; }
                const fd = new FormData();
                fd.append('action', 'update_delivery_field');
                fd.append('id', id);
                fd.append('field', field);
                fd.append('value', val);
                const r = await fetch('api.php', { method: 'POST', body: fd });
                const j = await r.json();
                if (j.success) { el.textContent = val; showToast('💾 Sparat'); }
                else { el.textContent = current; showToast('⚠️ Kunde inte spara', 'error'); }
            }
        });
    });
}
document.addEventListener('DOMContentLoaded', makeNumbersEditable);

// === Notifieringar ===
async function checkNotifications() {
    const fd = new FormData();
    fd.append('action', 'check_notifications');
    const r = await fetch('api.php', { method: 'POST', body: fd });
    const j = await r.json();
    const m = document.getElementById('notificationsMount');
    if (!m) return;
    m.innerHTML = '';
    if (j.alerts.open_long.length || j.alerts.unpaid.length || j.alerts.no_recent.length || j.alerts.low_stock.length) {
        let h = `<div class='bg-yellow-100 dark:bg-yellow-900 border border-yellow-400 dark:border-yellow-700 text-yellow-800 dark:text-yellow-200 p-3 rounded mb-4'><b>⚠️ Varningar</b><ul class='list-disc list-inside'>`;
        if (j.alerts.open_long.length) h += `<li>Balar öppna > ${j.limitDays} dagar</li>`;
        if (j.alerts.unpaid.length) h += `<li>${j.alerts.unpaid.length} obetalda leveranser</li>`;
        if (j.alerts.no_recent.length) h += `<li>Inga leveranser senaste 30 dagar</li>`;
        if (j.alerts.low_stock.length) h += `<li>Lågt lager (${j.alerts.low_stock[0].remaining} balar kvar)</li>`;
        h += '</ul></div>';
        m.innerHTML = h;
    } else {
        m.innerHTML = `<p class='text-sm text-gray-600 dark:text-gray-400 mb-3'>✅ Inga varningar</p>`;
    }
}
document.addEventListener('DOMContentLoaded', checkNotifications);
setInterval(checkNotifications, 600000);
