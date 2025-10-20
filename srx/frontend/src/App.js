import React, { useEffect, useState } from "react";
import Deliveries from "./components/Deliveries.jsx";
import DeliveryDetail from "./components/DeliveryDetail.jsx";
import Login from "./components/Login.jsx";
import Navbar from "./components/Navbar.jsx";
import Toast from "./components/Toast.jsx";

const API_URL = "http://localhost:5000/api";

export default function App() {
    const [user, setUser] = useState(localStorage.getItem("user") || null);
    const [view, setView] = useState("list");
    const [currentDelivery, setCurrentDelivery] = useState(null);
    const [toast, setToast] = useState(null);

    const showToast = (msg, type = "success") => {
        setToast({ msg, type });
        setTimeout(() => setToast(null), 3000);
    };

    if (!user) {
        return <Login onLogin={setUser} apiUrl={API_URL} showToast={showToast} />;
    }

    return (
        <div className="min-h-screen bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-gray-100 transition-colors duration-300">
            <Navbar
                onLogout={() => {
                    localStorage.removeItem("user");
                    setUser(null);
                }}
                apiUrl={API_URL}
                showToast={showToast}
            />

            <div className="max-w-6xl mx-auto p-4">
                {view === "list" && (
                    <Deliveries
                        apiUrl={API_URL}
                        user={user}
                        showToast={showToast}
                        onSelect={(id) => {
                            setCurrentDelivery(id);
                            setView("detail");
                        }}
                    />
                )}
                {view === "detail" && (
                    <DeliveryDetail
                        apiUrl={API_URL}
                        id={currentDelivery}
                        user={user}
                        showToast={showToast}
                        onBack={() => setView("list")}
                    />
                )}
            </div>
            {toast && <Toast {...toast} />}
        </div>
    );
}
