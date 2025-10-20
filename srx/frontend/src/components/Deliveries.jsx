import React, { useEffect, useState } from "react";

export default function Deliveries({ apiUrl, onSelect, showToast }) {
    const [deliveries, setDeliveries] = useState([]);

    async function loadDeliveries() {
        const res = await fetch(`${apiUrl}/deliveries`);
        const data = await res.json();
        if (data.success) setDeliveries(data.deliveries);
    }

    useEffect(() => {
        loadDeliveries();
    }, []);

    async function addDelivery(e) {
        e.preventDefault();
        const f = new FormData(e.target);
        const res = await fetch(`${apiUrl}/delivery`, { method: "POST", body: f });
        const j = await res.json();
        if (j.success) {
            showToast("✅ Leverans tillagd");
            loadDeliveries();
            e.target.reset();
        } else showToast("⚠️ Kunde inte lägga till", "error");
    }

    return (
        <div className="bg-white dark:bg-gray-800 p-4 rounded shadow mb-6">
            <h2 className="text-xl font-semibold mb-2">Leveranser</h2>

            <form
                onSubmit={addDelivery}
                className="grid grid-cols-1 md:grid-cols-4 gap-2 mb-4"
            >
                <input
                    name="supplier"
                    className="border rounded p-2 dark:bg-gray-700"
                    placeholder="Leverantör"
                    required
                />
                <input
                    type="date"
                    name="date"
                    className="border rounded p-2 dark:bg-gray-700"
                    required
                />
                <input
                    type="number"
                    name="bales"
                    className="border rounded p-2 dark:bg-gray-700"
                    placeholder="Antal balar"
                    min="1"
                    required
                />
                <button className="bg-green-600 text-white rounded p-2">Lägg till</button>
            </form>

            <div className="overflow-x-auto">
                <table className="min-w-full text-sm border dark:border-gray-700">
                    <thead className="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-100 uppercase">
                    <tr>
                        <th className="p-2">Leverantör</th>
                        <th className="p-2">Datum</th>
                        <th className="p-2">Antal</th>
                        <th className="p-2">Status</th>
                        <th className="p-2"></th>
                    </tr>
                    </thead>
                    <tbody>
                    {deliveries.map((d) => (
                        <tr
                            key={d.id}
                            className="border-t dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700"
                        >
                            <td className="p-2">{d.supplier}</td>
                            <td className="p-2">{d.delivery_date}</td>
                            <td className="p-2 text-center">{d.num_bales}</td>
                            <td className="p-2 text-center">
                                {d.stats.open} öppna / {d.stats.bad} felaktiga
                            </td>
                            <td className="p-2 text-center">
                                <button
                                    onClick={() => onSelect(d.id)}
                                    className="text-blue-600 hover:underline"
                                >
                                    Visa →
                                </button>
                            </td>
                        </tr>
                    ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
