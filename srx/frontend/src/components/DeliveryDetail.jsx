import React, { useEffect, useState } from "react";

export default function DeliveryDetail({ apiUrl, id, onBack, showToast }) {
    const [delivery, setDelivery] = useState(null);
    const [bales, setBales] = useState([]);

    async function loadDelivery() {
        const res = await fetch(`${apiUrl}/delivery/${id}`);
        const data = await res.json();
        if (data.success) {
            setDelivery(data.delivery);
            setBales(data.bales);
        }
    }

    useEffect(() => {
        loadDelivery();
    }, [id]);

    async function setStatus(baleId, status) {
        await fetch(`${apiUrl}/bale/${baleId}/status`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ status }),
        });
        loadDelivery();
    }

    return (
        <div className="bg-white dark:bg-gray-800 p-4 rounded shadow mb-6">
            <div className="my-2">
                <button
                    onClick={onBack}
                    className="text-blue-600 hover:underline mb-3 inline-block"
                >
                    ← Tillbaka
                </button>
            </div>
            <h2 className="text-xl font-semibold mb-2">
                Leverans från {delivery?.supplier} ({delivery?.delivery_date})
            </h2>

            <table className="min-w-full text-sm border dark:border-gray-700">
                <thead className="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-100 uppercase">
                <tr>
                    <th className="p-2">#</th>
                    <th className="p-2">Status</th>
                    <th className="p-2">Öppnad</th>
                    <th className="p-2">Stängd</th>
                    <th className="p-2">Bild</th>
                    <th className="p-2">Åtgärder</th>
                </tr>
                </thead>
                <tbody>
                {bales.map((b) => (
                    <tr key={b.id} className="border-t dark:border-gray-600">
                        <td className="p-2">{b.id}</td>
                        <td className="p-2">
                            {b.status === "open" ? "Öppen" : b.status === "closed" ? "Stängd" : "-"}
                        </td>
                        <td className="p-2">{b.open_date || "-"}</td>
                        <td className="p-2">{b.close_date || "-"}</td>
                        <td className="p-2 text-center">
                            {b.photo ? (
                                <a href={b.photo} target="_blank" className="text-blue-600 underline">
                                    Visa bild
                                </a>
                            ) : (
                                "-"
                            )}
                        </td>
                        <td className="p-2 flex flex-wrap gap-1 justify-center">
                            <button
                                onClick={() => setStatus(b.id, "open")}
                                className="px-2 py-1 text-xs rounded bg-gray-200 dark:bg-blue-700 dark:hover:bg-blue-600"
                            >
                                Öppen
                            </button>
                            <button
                                onClick={() => setStatus(b.id, "closed")}
                                className="px-2 py-1 text-xs rounded bg-gray-200 dark:bg-blue-700 dark:hover:bg-blue-600"
                            >
                                Stängd
                            </button>
                        </td>
                    </tr>
                ))}
                </tbody>
            </table>
        </div>
    );
}
