import React from "react";

export default function Toast({ msg, type }) {
    const color =
        type === "error"
            ? "bg-red-600"
            : type === "info"
                ? "bg-blue-600"
                : "bg-green-600";
    return (
        <div
            className={`${color} text-white px-4 py-2 rounded shadow-md fixed bottom-4 right-4 transition-all duration-300`}
        >
            {msg}
        </div>
    );
}
