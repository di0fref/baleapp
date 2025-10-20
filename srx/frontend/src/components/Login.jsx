import React, { useState } from "react";

export default function Login({ onLogin, apiUrl, showToast }) {
    const [username, setUsername] = useState("");
    const [password, setPassword] = useState("");

    async function handleLogin(e) {
        e.preventDefault();
        const res = await fetch(`${apiUrl}/login`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ username, password }),
        });
        const data = await res.json();
        if (data.success) {
            localStorage.setItem("user", username);
            onLogin(username);
        } else showToast("Fel användarnamn eller lösenord", "error");
    }

    return (
        <div className="flex items-center justify-center h-screen bg-gray-100 dark:bg-gray-900">
            <div className="bg-white dark:bg-gray-800 p-6 rounded shadow w-full max-w-sm">
                <h1 className="text-2xl font-bold mb-4 text-center">🌾 Höbalsapp</h1>
                <form onSubmit={handleLogin} className="space-y-3">
                    <input
                        value={username}
                        onChange={(e) => setUsername(e.target.value)}
                        className="w-full border rounded p-2 dark:bg-gray-700"
                        placeholder="Användarnamn"
                        required
                    />
                    <input
                        type="password"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        className="w-full border rounded p-2 dark:bg-gray-700"
                        placeholder="Lösenord"
                        required
                    />
                    <button className="bg-green-600 text-white w-full rounded p-2">
                        Logga in
                    </button>
                </form>
            </div>
        </div>
    );
}
