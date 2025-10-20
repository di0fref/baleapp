import axios from "axios";

const api = axios.create({
    baseURL: "http://localhost:4000/api"
});

export const getDeliveries = () => api.get("/deliveries").then(r => r.data);
export const getDelivery = id => api.get(`/delivery/${id}`).then(r => r.data);
export const addDelivery = data => api.post("/delivery", data).then(r => r.data);
export const updateDelivery = (data) => api.post("/delivery/update", data).then(r => r.data);
export const uploadInvoice = (id, file) => {
    const fd = new FormData();
    fd.append("file", file);
    return api.post(`/invoice/${id}`, fd);
};
export const uploadPhoto = (id, file) => {
    const fd = new FormData();
    fd.append("file", file);
    return api.post(`/photo/${id}`, fd);
};
export const updateBale = (data) => api.post("/bale/update", data);
export const toggleFlag = (data) => api.post("/bale/flag", data);
export const getReport = () => api.get("/report").then(r => r.data);
export const getPrediction = () => api.get("/predict").then(r => r.data);
import React, { useState, useEffect } from "react";
import { Routes, Route, useNavigate } from "react-router-dom";
import DeliveriesList from "./components/DeliveriesList";
import DeliveryDetail from "./components/DeliveryDetail";
import Login from "./components/Login";
import { getReport, getPrediction } from "./api";

export default function App() {
    const [user, setUser] = useState(localStorage.getItem("user") || "");
    const [dark, setDark] = useState(localStorage.theme === "dark");
    const navigate = useNavigate();

    useEffect(() => {
        document.documentElement.classList.toggle("dark", dark);
    }, [dark]);

    const logout = () => {
        localStorage.removeItem("user");
        setUser("");
        navigate("/login");
    };

    const showModal = (title, content) => {
        const modal = document.createElement("div");
        modal.className =
            "fixed inset-0 bg-black/40 flex items-center justify-center z-50";
        modal.innerHTML = `
      <div class='bg-white dark:bg-gray-800 p-5 rounded shadow-lg max-w-lg w-full relative'>
        <button class='absolute top-2 right-3 text-gray-400 hover:text-gray-600'
          onclick='this.closest(".fixed").remove()'>✖</button>
        <h2 class='text-xl font-semibold mb-2'>${title}</h2>
        ${content}
      </div>`;
        document.body.appendChild(modal);
    };

    const openReport = async () => {
        const r = await getReport();
        const html = `
      <p><b>Genomsnittlig öppentid:</b> ${r.avgDays} dagar</p>
      <p><b>Öppnade senaste ${r.period || 30} dagarna:</b> ${r.openedCount} (${r.dailyRate}/dag)</p>
      <p><b>Kvar i lager:</b> ${r.remaining}</p>`;
        showModal("📊 Rapport", html);
    };

    const openForecast = async () => {
        const f = await getPrediction();
        let html = `
      <p><b>Genomsnittligt pris per bal:</b> ${f.avg_price} kr</p>
      <p><b>Förbrukningstakt:</b> ${f.daily_rate} balar/dag</p>
      <p><b>Kvar i lager:</b> ${f.remaining}</p>
      <table class='w-full text-sm border mt-2'>
        <thead class='bg-gray-200 dark:bg-gray-700'>
          <tr><th class='p-1'>Månad</th><th class='p-1'>Balar</th><th class='p-1'>Kostnad</th></tr>
        </thead><tbody>`;
        f.forecast.forEach(x => {
            html += `<tr><td class='p-1'>${x.month}</td><td>${x.bales_used}</td><td>${x.estimated_cost} kr</td></tr>`;
        });
        html += "</tbody></table>";
        showModal("💰 Prognos (6 mån)", html);
    };

    if (!user) return <Login setUser={setUser} />;

    return (
        <div className="max-w-6xl mx-auto p-4">
            <div className="flex justify-between items-center mb-4">
                <h1 className="text-2xl font-bold">🌾 Höbalsapp</h1>
                <div className="flex items-center gap-2">
                    <button
                        onClick={openReport}
                        className="bg-indigo-600 text-white text-sm px-3 py-1 rounded"
                    >
                        📊 Rapport
                    </button>
                    <button
                        onClick={openForecast}
                        className="bg-yellow-600 text-white text-sm px-3 py-1 rounded"
                    >
                        💰 Prognos
                    </button>
                    <button
                        onClick={() => setDark(!dark)}
                        className="bg-gray-200 dark:bg-gray-700 px-3 py-1 rounded"
                    >
                        {dark ? "☀️" : "🌙"}
                    </button>
                    <button
                        onClick={logout}
                        className="bg-red-600 hover:bg-red-700 text-white text-sm px-3 py-1 rounded"
                    >
                        Logga ut
                    </button>
                </div>
            </div>

            <Routes>
                <Route path="/" element={<DeliveriesList />} />
                <Route path="/delivery/:id" element={<DeliveryDetail />} />
            </Routes>
        </div>
    );
}
