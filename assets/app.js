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

// === Tema (dark mode) ===
const themeToggleBtn = document.getElementById('themeToggle');
function updateTheme() {
    if (localStorage.theme === 'dark') {
        document.documentElement.classList.add('dark'); if (themeToggleBtn) themeToggleBtn.textContent = '☀️';
    } else {
        document.documentElement.classList.remove('dark'); if (themeToggleBtn) themeToggleBtn.textContent = '🌙';
    }
}
if (themeToggleBtn) {
    updateTheme();
    themeToggleBtn.addEventListener('click', () => {
        localStorage.theme = localStorage.theme === 'dark' ? 'light' : 'dark';
        updateTheme();
    });
}

// === Modal ===
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

// === API helper ===
async function postAction(action, data = {}) {
    const fd = new FormData();
    fd.append('action', action);
    for (const [k, v] of Object.entries(data)) fd.append(k, v);
    const r = await fetch('api.php', { method: 'POST', body: fd });
    return r.json();
}

// === Rapport & Prognos ===
async function generateReport() {
    const j = await postAction('generate_full_report');
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
async function showCostPrediction() {
    const j = await postAction('predict_costs');
    if (!j.success) return showToast('Kunde inte hämta prognos', 'error');
    let html = `<h3 class='text-xl font-semibold mb-2'>💰 Kostnadsprognos (6 månader)</h3>`;
    html += `<p><b>Genomsnittligt pris per bal:</b> ${j.avg_price} kr</p>`;
    html += `<p><b>Förbrukningstakt:</b> ${j.daily_rate} balar/dag</p>`;
    html += `<p><b>Kvar i lager:</b> ${j.remaining} balar</p>`;
    html += `<hr class='my-2'><table class='w-full text-sm border border-gray-300 dark:border-gray-700'>
    <thead class='bg-gray-200 dark:bg-gray-800'>
      <tr><th class='p-2'>Månad</th><th class='p-2'>Förväntade balar</th><th class='p-2'>Beräknad kostnad</th></tr>
    </thead><tbody>`;
    j.forecast.forEach(f => {
        html += `<tr class='border-t dark:border-gray-700'><td class='p-2'>${f.month}</td><td class='p-2'>${f.bales_used}</td><td class='p-2'>${f.estimated_cost} kr</td></tr>`;
    });
    html += '</tbody></table>';
    showModal(html);
}
document.getElementById('reportBtn')?.addEventListener('click', generateReport);
document.getElementById('forecastBtn')?.addEventListener('click', showCostPrediction);

// === Filuppladdningar ===
async function uploadInvoice(id) {
    const inp = document.createElement('input');
    inp.type = 'file'; inp.accept = 'application/pdf';
    inp.onchange = async () => {
        const f = inp.files[0]; if (!f) return;
        const fd = new FormData();
        fd.append('action', 'upload_invoice_file');
        fd.append('id', id);
        fd.append('invoice_file', f);
        const r = await fetch('api.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.success) App.loadDeliveries();
    };
    inp.click();
}
async function deleteInvoice(id) {
    if (!confirm('Ta bort faktura?')) return;
    await postAction('delete_invoice_file', { id });
    showToast('🗑️ Faktura borttagen'); App.loadDeliveries();
}
async function uploadPhoto(baleId) {
    const inp = document.createElement('input');
    inp.type = 'file'; inp.accept = 'image/*';
    inp.onchange = async () => {
        const f = inp.files[0]; if (!f) return;
        const fd = new FormData();
        fd.append('action', 'upload_photo');
        fd.append('id', baleId);
        fd.append('photo', f);
        const r = await fetch('api.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.success) { showToast('📷 Bild uppladdad'); App.loadDelivery(App.currentDelivery); }
    };
    inp.click();
}
async function deletePhoto(baleId) {
    if (!confirm('Ta bort bilden?')) return;
    const j = await postAction('delete_photo', { id: baleId });
    if (j.success) { showToast('🗑️ Bild borttagen'); App.loadDelivery(App.currentDelivery); }
    else showToast('⚠️ Kunde inte ta bort bilden', 'error');
}

// === Bale & Delivery actions ===
async function updateDelivery(id, paid) {
    await postAction('update_delivery', { id, paid });
    showToast('💾 Sparat');
}
async function setStatus(baleId, status) {
    await postAction('update_bale_status', { id: baleId, status });
    App.loadDelivery(App.currentDelivery);
}
async function toggleFlag(baleId, flag, value) {
    await postAction('toggle_flag', { id: baleId, flag, value });
    App.loadDelivery(App.currentDelivery);
}

// === Templates (måste redan vara laddade via assets/templates.js) ===
// Templates.loader
// Templates.deliveriesTable(deliveries)
// Templates.deliveryDetail(delivery, bales)

// === SPA core ===
const App = {
    el: document.getElementById('app'),
    currentDelivery: null,

    async loadDeliveries() {
        this.el.innerHTML = Templates.loader;
        const j = await postAction('list_deliveries');
        if (!j.success) return showToast('Kunde inte hämta leveranser', 'error');
        this.el.innerHTML = Templates.deliveriesTable(j.deliveries);
        history.pushState({ view: 'deliveries' }, '', '#/');
        await checkNotifications();
    },

    async loadDelivery(id) {
        this.el.innerHTML = Templates.loader;
        const j = await postAction('get_delivery', { id });
        if (!j.success) return showToast('Kunde inte hämta leverans', 'error');
        this.currentDelivery = id;
        this.el.innerHTML = Templates.deliveryDetail(j.delivery, j.bales);
        history.pushState({ view: 'delivery', id }, '', `#/delivery/${id}`);
    }
};

// === Global (hash) navigation ===
window.onpopstate = e => {
    if (!e.state || e.state.view === 'deliveries') App.loadDeliveries();
    else if (e.state.view === 'delivery') App.loadDelivery(e.state.id);
};

// === Event DELEGERING på #app ===
// === Event DELEGERING på #app ===
document.addEventListener('DOMContentLoaded', () => {
    const app = document.getElementById('app');

    // 🟢 Inline edit DATE fields
    app.addEventListener('click', (e) => {
        const el = e.target.closest('.editable-date');
        if (!el) return;
        if (el.dataset.locked === 'true' || el.querySelector('input')) return;

        const id = el.dataset.id;
        const field = el.dataset.field;
        const current = el.textContent.trim();
        const input = document.createElement('input');
        input.type = 'date';
        input.className = 'border rounded p-1 text-sm w-full dark:bg-gray-700';
        input.value = (current && current !== '-') ? current : new Date().toISOString().split('T')[0];
        el.innerHTML = '';
        el.appendChild(input);
        input.focus();

        const save = async () => {
            const newVal = input.value.trim();
            const oldVal = current.trim();
            if (!newVal || newVal === oldVal || newVal === '-') {
                el.textContent = oldVal || '-';
                return;
            }
            const j = await postAction('update_date', { id, field, value: newVal });
            if (j.success) {
                el.textContent = newVal;
                showToast('📅 Datum sparat');
            } else {
                el.textContent = oldVal || '-';
                showToast('⚠️ Kunde inte spara datum', 'error');
            }
        };
        input.addEventListener('change', save);
        input.addEventListener('blur', save);
    });

    // 🟢 Inline edit NUMBER fields (price, weight)
    app.addEventListener('click', (e) => {
        const el = e.target.closest('.editable-num');
        if (!el) return;
        if (el.querySelector('input')) return;

        const id = el.dataset.id;
        const field = el.dataset.field;
        const current = el.textContent.trim();
        const input = document.createElement('input');
        input.type = 'number';
        input.step = '0.01';
        input.value = current.replace(',', '.');
        input.className = 'border rounded p-1 text-sm w-24 dark:bg-gray-700';
        el.innerHTML = '';
        el.appendChild(input);
        input.focus();

        const save = async () => {
            const val = input.value.trim();
            if (!val || val === current) {
                el.textContent = current;
                return;
            }
            const j = await postAction('update_delivery_field', { id, field, value: val });
            if (j.success) {
                el.textContent = val;
                showToast('💾 Sparat');
            } else {
                el.textContent = current;
                showToast('⚠️ Kunde inte spara', 'error');
            }
        };
        input.addEventListener('change', save);
        input.addEventListener('blur', save);
    });

    // 🟢 Handle "Lägg till leverans" form
    app.addEventListener('submit', async (e) => {
        const form = e.target.closest('#addDeliveryForm');
        if (!form) return;
        e.preventDefault();
        const fd = new FormData(form);
        if (!fd.get('supplier') || !fd.get('date') || !fd.get('bales')) {
            showToast('Fyll i alla fält', 'error');
            return;
        }
        fd.append('action', 'add_delivery');
        const r = await fetch('api.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.success) {
            showToast('✅ Leverans tillagd');
            App.loadDeliveries();
        } else {
            showToast('⚠️ Kunde inte lägga till leverans', 'error');
        }
    });
});


// === Knapp-kopplingar utanför #app ===
document.getElementById('logoutBtn')?.addEventListener('click', () => { window.location = '?logout'; });

// === Notifieringar (banner högst upp i leveranslistan) ===
async function checkNotifications() {
    const j = await postAction('check_notifications');
    const m = document.getElementById('notificationsMount');
    if (!m) return;
    m.innerHTML = '';
    if (j.alerts.open_long.length || j.alerts.unpaid.length || j.alerts.no_recent.length || j.alerts.low_stock.length) {
        let h = `<div class='bg-yellow-100 dark:bg-yellow-900 border border-yellow-400 dark:border-yellow-700 text-yellow-800 dark:text-yellow-200 p-3 rounded mb-4'>
      <b>⚠️ Varningar</b><ul class='list-disc list-inside'>`;
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

async function loadWarmRisks(){
    const fd = new FormData();
    fd.append('action','get_warm_risk');
    const r = await fetch('api.php', {method:'POST', body:fd});
    const j = await r.json();
    if(!j.success) return;

    j.data.forEach(risk=>{
        const el = document.querySelector(`[data-bale="${risk.bale_id}"] .warm-risk`);
        if(el){
            el.textContent = `🔥 Risk om ~${risk.pred_days} dagar (${risk.pred_date})`;
            el.classList.add('text-orange-600','text-xs');
        }
    });
}
document.addEventListener('DOMContentLoaded', loadWarmRisks);

// === Start ===
document.addEventListener('DOMContentLoaded', () => {
    App.loadDeliveries();
    checkNotifications();
    setInterval(checkNotifications, 600000);
});

// === Globala helpers som används av templates (måste vara i global scope) ===
window.App = App;
window.updateDelivery = updateDelivery;
window.uploadInvoice = uploadInvoice;
window.deleteInvoice = deleteInvoice;
window.setStatus = setStatus;
window.toggleFlag = toggleFlag;
window.uploadPhoto = uploadPhoto;
window.deletePhoto = deletePhoto;
