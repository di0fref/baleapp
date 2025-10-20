import React from "react";

export default function Navbar({ onLogout, apiUrl, showToast }) {
    async function handleReport() {
        const res = await fetch(`${apiUrl}/report`);
        const j = await res.json();
        if (!j.success) return showToast("Kunde inte generera rapport", "error");
        alert(
            `📊 Rapport\n\nGenomsnittlig öppentid: ${j.avgDays} dagar\n` +
            `Öppnade senaste ${j.period} dagarna: ${j.openedCount}\n` +
            `Kvar i lager: ${j.remaining}`
        );
    }

    async function handleForecast() {
        const res = await fetch(`${apiUrl}/forecast`);
        const j = await res.json();
        if (!j.success) return showToast("Kunde inte hämta prognos", "error");
        alert(
            `💰 Kostnadsprognos\n\nGenomsnittligt pris/bal: ${j.avg_price} kr\n` +
            `Förbrukningstakt: ${j.daily_rate} balar/dag`
        );
    }

    function toggleTheme() {
        const dark = document.documentElement.classList.toggle("dark");
        localStorage.theme = dark ? "dark" : "light";
    }

    return (
        <div className="flex justify-between items-center p-4 bg-gray-50 dark:bg-gray-800 shadow">
            <h1 className="text-2xl font-bold">🌾 Höbalsapp</h1>
            <div className="flex items-center gap-2">
                <button
                    onClick={handleReport}
                    className="bg-indigo-600 text-white text-sm px-3 py-1 rounded"
                >
                    📊 Rapport
                </button>
                <button
                    onClick={handleForecast}
                    className="bg-yellow-600 text-white text-sm px-3 py-1 rounded"
                >
                    💰 Prognos
                </button>
                <button
                    onClick={toggleTheme}
                    className="bg-gray-200 dark:bg-gray-700 px-3 py-1 rounded"
                >
                    🌙
                </button>
                <button
                    onClick={onLogout}
                    className="bg-red-600 hover:bg-red-700 text-white text-sm px-3 py-1 rounded"
                >
                    Logga ut
                </button>
            </div>
        </div>
    );
}
