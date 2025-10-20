import fetch from "node-fetch";

// Hämtar prognosdata (medeltemperatur kommande 48 timmar)
export async function getSmhiForecast(lat = 59.3793, lon = 13.5036) {
    try {
        const url = `https://opendata-download-metfcst.smhi.se/api/category/pmp3g/version/2/geotype/point/lon/${lon}/lat/${lat}/data.json`;
        const r = await fetch(url);
        const j = await r.json();

        const temps = j.timeSeries
            .slice(0, 48)
            .map(t => t.parameters.find(p => p.name === "t").values[0]);
        const avg = temps.reduce((a, b) => a + b, 0) / temps.length;

        return Number(avg.toFixed(1));
    } catch (e) {
        console.error("SMHI error:", e);
        return null;
    }
}
