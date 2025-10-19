// === HTML Templates ===
const Templates = {
    loader: `<div class='text-center text-gray-500 dark:text-gray-400 py-10'>Laddar...</div>`,

    deliveryRow: d => `

deliveryRow: d => \`
    <tr class="border-t border-l-4 ${d.stats.unreimbursed ? 'border-red-400' : 'border-gray-200'} 
               dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-800 
               transition-colors">

      <td class="p-2">${d.supplier}</td>
      <td class="p-2">${d.delivery_date}</td>
      <td class="p-2 text-center">${d.stats.total}</td>
      <td class="p-2 text-center">${d.stats.open} öppna / ${d.stats.bad} felaktiga</td>
      <td class="p-2 text-center">
        <input type="checkbox" ${d.paid ? 'checked' : ''} onchange="updateDelivery(${d.id},this.checked?1:0)">
      </td>
      <td class="p-2 text-center">
        ${d.invoice_file ? `
          <a href="${d.invoice_file}" target="_blank" class="text-blue-600 underline">Visa</a>
          <button onclick="deleteInvoice(${d.id})" class="text-xs text-red-600">🗑️</button>
        ` : `
          <button onclick="uploadInvoice(${d.id})" class="text-sm bg-gray-200 px-2 py-1 rounded dark:bg-blue-700">📎 Ladda upp</button>
        `}
      </td>
      <td class="p-2 text-center">
        <button onclick="App.loadDelivery(${d.id})" class="text-blue-600 hover:underline">Visa →</button>
      </td>
    </tr>
  `,

    deliveriesTable: ds => `
    <div id="notificationsMount"></div>
    <div class="bg-white dark:bg-gray-800 p-4 rounded shadow mb-6">
      <h2 class="text-xl font-semibold mb-2">Leveranser</h2>
      <form id="addDeliveryForm" class="grid grid-cols-1 md:grid-cols-4 gap-2 mb-4">
        <input name="supplier" class="border rounded p-2 dark:bg-gray-700" placeholder="Leverantör" required>
        <input type="date" name="date" class="border rounded p-2 dark:bg-gray-700" required>
        <input type="number" name="bales" class="border rounded p-2 dark:bg-gray-700" placeholder="Antal balar" min="1" required>
        <button class="bg-green-600 text-white rounded p-2">Lägg till</button>
      </form>
      <table class="min-w-full text-sm border dark:border-gray-700">
        <thead class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-100 uppercase">
          <tr>
            <th class="p-2">Leverantör</th><th class="p-2">Datum</th><th class="p-2">Antal</th>
            <th class="p-2">Status</th><th class="p-2">Betald</th><th class="p-2">Faktura</th><th class="p-2"></th>
          </tr>
        </thead>
        <tbody>${ds.map(Templates.deliveryRow).join('')}</tbody>
      </table>
    </div>
  `,
};
Templates.login = `
  <div class="bg-white dark:bg-gray-800 p-6 rounded shadow max-w-sm mx-auto mt-20">
    <h1 class="text-2xl font-bold mb-4 text-center">🌾 Höbalsapp</h1>
    <form id="loginForm" class="space-y-3">
      <input name="username" class="w-full border rounded p-2 dark:bg-gray-700" placeholder="Användarnamn" required>
      <input type="password" name="password" class="w-full border rounded p-2 dark:bg-gray-700" placeholder="Lösenord" required>
      <button class="bg-green-600 text-white w-full rounded p-2">Logga in</button>
    </form>
  </div>
`;
Templates.deliveryDetail = (delivery, bales) => `
  <div class="bg-white dark:bg-gray-800 p-4 rounded shadow mb-6">
    <div class="my-2">
      <a href="#/" class="text-blue-600 hover:underline">&larr; Tillbaka</a>
    </div>

    <div class="bg-gray-100 dark:bg-gray-700 p-4 rounded mb-4 grid grid-cols-1 md:grid-cols-3 gap-4">
      <div>
        <p><b>Leverantör:</b> ${delivery.supplier}</p>
        <p><b>Datum:</b> ${delivery.delivery_date}</p>
        <p><b>Antal balar:</b> ${delivery.num_bales}</p>
      </div>
      <div>
        <p><b>Pris:</b>
          <span class="editable-num cursor-pointer text-blue-600 _underline"
                data-id="${delivery.id}" data-field="price">
            ${Number(delivery.price || 0).toFixed(2)}
          </span> kr
        </p>
        <p><b>Vikt:</b>
          <span class="editable-num cursor-pointer text-blue-600 _underline"
                data-id="${delivery.id}" data-field="weight">
            ${Number(delivery.weight || 0).toFixed(1)}
          </span> kg
        </p>
        <p><b>Betald:</b>
          <input type="checkbox" ${delivery.paid ? 'checked' : ''}
                 onchange="updateDelivery(${delivery.id}, this.checked ? 1 : 0)">
        </p>
      </div>
      <div>
        <p><b>Faktura:</b>
          ${delivery.invoice_file
    ? `<a href="${delivery.invoice_file}" target="_blank" class="text-blue-600_ underline">Visa</a>
               <button onclick="deleteInvoice(${delivery.id})" class="text-xs text-red-600">🗑️</button>`
    : `<button onclick="uploadInvoice(${delivery.id})"
                       class="text-sm bg-gray-200 px-2 py-1 rounded dark:bg-blue-700 dark:hover:bg-blue-600">📎 Ladda upp</button>`}
        </p>
        <p><b>Skapad:</b> ${delivery.created_at}</p>
      </div>
    </div>

    <p class="mb-2 text-sm">
      Totalt: ${bales.length} • 
      Öppna: ${bales.filter(b => b.status === 'open').length} • 
      Stängda: ${bales.filter(b => b.status === 'closed').length} • 
      Felaktiga: ${bales.filter(b => b.is_bad).length}
    </p>

    <table class="min-w-full text-sm border dark:border-gray-700">
      <thead class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-100 uppercase">
        <tr>
          <th class="p-2">#</th>
          <th class="p-2">Status</th>
          <th class="p-2">Öppnad</th>
          <th class="p-2">Stängd</th>
          <th class="p-2">Varm</th>
          <th class="p-2">Dagar</th>
          <th class="p-2">Bild</th>
          <th class="p-2">Åtgärder</th>
          <th class="p-2">Risk</th>

        </tr>
      </thead>
      <tbody>
        ${bales.map(b => {
    let days = '-';
    if (b.open_date) {
        const d1 = new Date(b.open_date);
        const d2 = b.close_date ? new Date(b.close_date) : new Date();
        days = Math.floor((d2 - d1) / (1000 * 60 * 60 * 24)) + ' dagar';
    }
    return `
<tr class="border-t dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
              <td class="p-2">${b.id}</td>
              <td class="p-2">
                ${b.status
        ? `<span class='px-2 py-1 text-xs rounded ${b.status === 'open'
            ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800'}'>
                      ${b.status === 'open' ? 'Öppen' : 'Stängd'}
                     </span>`
        : ''}
                ${b.is_bad
        ? `<span class='px-2 py-1 text-xs rounded bg-red-100 text-red-800'>Felaktig</span>`
        : ''}
                ${b.is_reimbursed
        ? `<span class='px-2 py-1 text-xs rounded bg-yellow-100 text-yellow-800'>Ersatt</span>`
        : ''}
              </td>
              <td class="p-2">
                <div class="flex items-center gap-1">
                  <span class="editable-date cursor-pointer text-blue-600 _underline"
                        data-id="${b.id}" data-field="open_date"
                        data-locked="${(b.status === 'open' || b.status === 'closed') ? 'false' : 'true'}">
                    ${b.open_date || '-'}
                  </span>
                  ${b.opened_by ? `<span class='text-xs text-gray-500 italic'>(av ${b.opened_by})</span>` : ''}
                </div>
              </td>
              <td class="p-2">
                <div class="flex items-center gap-1">
                  <span class="editable-date cursor-pointer text-blue-600 _underline"
                        data-id="${b.id}" data-field="close_date"
                        data-locked="${(b.status === 'open' || b.status === 'closed') ? 'false' : 'true'}">
                    ${b.close_date || '-'}
                  </span>
                  ${b.closed_by ? `<span class='text-xs text-gray-500 italic'>(av ${b.closed_by})</span>` : ''}
                </div>
              </td>
              
              
                    <td class="p-2">
                      <div class="flex items-center gap-1">
                        <span class="editable-date cursor-pointer text-orange-600 _underline"
                              data-id="${b.id}" data-field="warm_date"
                              data-locked="${(b.status === 'open' || b.status === 'closed') ? 'false' : 'true'}">
                          ${b.warm_date || '-'}
                        </span>
                      </div>
                    </td>



              <td class="p-2 text-center">${days}</td>
              <td class="p-2 text-center">
                ${b.photo
        ? `<div class="flex items-center gap-1 justify-center">
                       <a href="${b.photo}" target="_blank" class="text-blue-600 _underline">Visa bild</a>
                       <button onclick="deletePhoto(${b.id})" class="text-red-600 hover:text-red-800 text-sm" title="Ta bort bild">🗑️</button>
                     </div>`
        : `<button onclick="uploadPhoto(${b.id})" class="px-2 py-1 _border rounded text-xs bg-gray-200 dark:bg-blue-700 dark:hover:bg-blue-600">Ladda upp</button>`}
              </td>
              <td class="p-2 flex flex-wrap gap-1 justify-center">
                <button onclick="setStatus(${b.id}, 'open')" class="px-2 py-1 rounded text-xs bg-gray-200 dark:bg-blue-700 dark:hover:bg-blue-600">Öppen</button>
                <button onclick="setStatus(${b.id}, 'closed')" class="px-2 py-1 rounded text-xs bg-gray-200 dark:bg-blue-700 dark:hover:bg-blue-600">Stängd</button>
                <button onclick="toggleFlag(${b.id}, 'is_bad', ${b.is_bad ? 0 : 1})" class="px-2 py-1 rounded text-xs bg-gray-200 dark:bg-blue-700 dark:hover:bg-blue-600">Felaktig</button>
                <button onclick="toggleFlag(${b.id}, 'is_reimbursed', ${b.is_reimbursed ? 0 : 1})" class="px-2 py-1 rounded text-xs bg-gray-200 dark:bg-blue-700 dark:hover:bg-blue-600">Ersatt</button>
              </td>
              <td class="p-2 warm-risk" data-bale="${b.id}">–</td>

            </tr>
          `;
}).join('')}
      </tbody>
    </table>
  </div>
`;


