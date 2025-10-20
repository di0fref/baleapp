import express from "express";
import sqlite3 from "sqlite3";
import { open } from "sqlite";
import cors from "cors";
import fileUpload from "express-fileupload";
import fs from "fs";
import path from "path";
import fetch from "node-fetch";

const app = express();
app.use(cors());
app.use(express.json());
app.use(fileUpload());

const dbPath = path.resolve("./backend/db/haybales.db");

// === Initiera DB ===
const db = await open({ filename: dbPath, driver: sqlite3.Database });
await db.exec(`
  CREATE TABLE IF NOT EXISTS deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    supplier TEXT,
    delivery_date TEXT,
    num_bales INTEGER DEFAULT 0,
    paid INTEGER DEFAULT 0,
    invoice_file TEXT,
    price REAL DEFAULT 0,
    weight REAL DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
  );
`);
await db.exec(`
  CREATE TABLE IF NOT EXISTS bales (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    delivery_id INTEGER,
    status TEXT,
    is_bad INTEGER DEFAULT 0,
    is_reimbursed INTEGER DEFAULT 0,
    open_date TEXT,
    close_date TEXT,
    warm_date TEXT,
    photo TEXT,
    opened_by TEXT,
    closed_by TEXT,
    marked_bad_by TEXT,
    reimbursed_date TEXT,
    FOREIGN KEY(delivery_id) REFERENCES deliveries(id)
  );
`);
await db.exec(`
  CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE
  );
`);

const ensureUser = async (name) => {
    const exists = await db.get("SELECT * FROM users WHERE username=?", name);
    if (!exists) await db.run("INSERT INTO users(username) VALUES(?)", name);
};
await ensureUser("Erika");
await ensureUser("Fredrik");

// === Hjälpfunktioner ===
const uploadDir = path.resolve("./backend/uploads");
if (!fs.existsSync(uploadDir)) fs.mkdirSync(uploadDir, { recursive: true });

const saveFile = (file, subdir = "") => {
    const dir = path.join(uploadDir, subdir);
    if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
    const fn = Date.now() + "_" + file.name.replace(/[^a-zA-Z0-9_.-]/g, "_");
    const dest = path.join(dir, fn);
    file.mv(dest);
    return `/uploads/${subdir ? subdir + "/" : ""}${fn}`;
};

// === Endpoints ===

// -- List all deliveries --
app.get("/api/deliveries", async (_, res) => {
    const deliveries = await db.all("SELECT * FROM deliveries ORDER BY id DESC");
    for (const d of deliveries) {
        const bales = await db.all("SELECT status,is_bad,is_reimbursed FROM bales WHERE delivery_id=?", d.id);
        d.stats = {
            total: bales.length,
            open: bales.filter(b => b.status === "open").length,
            bad: bales.filter(b => b.is_bad).length,
            unreimbursed: bales.filter(b => b.is_bad && !b.is_reimbursed).length
        };
    }
    res.json({ success: true, deliveries });
});

// -- Get single delivery --
app.get("/api/delivery/:id", async (req, res) => {
    const id = req.params.id;
    const delivery = await db.get("SELECT * FROM deliveries WHERE id=?", id);
    const bales = await db.all("SELECT * FROM bales WHERE delivery_id=? ORDER BY id ASC", id);
    res.json({ success: true, delivery, bales });
});

// -- Add delivery --
app.post("/api/delivery", async (req, res) => {
    const { supplier, date, bales } = req.body;
    const r = await db.run("INSERT INTO deliveries(supplier,delivery_date,num_bales) VALUES(?,?,?)", supplier, date, bales);
    const did = r.lastID;
    for (let i = 0; i < parseInt(bales); i++) await db.run("INSERT INTO bales(delivery_id) VALUES(?)", did);
    res.json({ success: true });
});

// -- Update delivery paid, price, weight --
app.post("/api/delivery/update", async (req, res) => {
    const { id, field, value, paid } = req.body;
    if (field) {
        await db.run(`UPDATE deliveries SET ${field}=? WHERE id=?`, value, id);
    } else if (typeof paid !== "undefined") {
        await db.run("UPDATE deliveries SET paid=? WHERE id=?", paid, id);
    }
    res.json({ success: true });
});

// -- Upload invoice --
app.post("/api/invoice/:id", async (req, res) => {
    if (!req.files?.file) return res.json({ success: false });
    const filePath = saveFile(req.files.file, "invoices");
    await db.run("UPDATE deliveries SET invoice_file=? WHERE id=?", filePath, req.params.id);
    res.json({ success: true, file: filePath });
});

// -- Upload bale photo --
app.post("/api/photo/:id", async (req, res) => {
    if (!req.files?.file) return res.json({ success: false });
    const filePath = saveFile(req.files.file, "bales");
    await db.run("UPDATE bales SET photo=? WHERE id=?", filePath, req.params.id);
    res.json({ success: true, file: filePath });
});

// -- Update bale status/date --
app.post("/api/bale/update", async (req, res) => {
    const { id, status, field, value } = req.body;
    if (status)
        await db.run("UPDATE bales SET status=?, open_date=CASE WHEN ?='open' THEN COALESCE(open_date,date('now')) ELSE open_date END WHERE id=?", status, status, id);
    else if (field)
        await db.run(`UPDATE bales SET ${field}=? WHERE id=?`, value, id);
    res.json({ success: true });
});

// -- Toggle flags (bad/reimbursed) --
app.post("/api/bale/flag", async (req, res) => {
    const { id, flag, value } = req.body;
    await db.run(`UPDATE bales SET ${flag}=? WHERE id=?`, value, id);
    res.json({ success: true });
});

// -- Reports & Cost prediction --
app.get("/api/report", async (_, res) => {
    const rows = await db.all("SELECT open_date, close_date FROM bales WHERE open_date IS NOT NULL AND close_date IS NOT NULL");
    let totalDays = 0;
    rows.forEach(r => totalDays += (new Date(r.close_date) - new Date(r.open_date)) / 86400000);
    const avgDays = rows.length ? (totalDays / rows.length).toFixed(1) : 0;
    const open30 = await db.all("SELECT open_date FROM bales WHERE open_date >= date('now','-30 day')");
    const dailyRate = open30.length / 30;
    const remaining = await db.get("SELECT COUNT(*) AS c FROM bales WHERE open_date IS NULL");
    res.json({ success: true, avgDays, openedCount: open30.length, dailyRate, remaining: remaining.c });
});

// -- Predict future costs --
app.get("/api/predict", async (_, res) => {
    const avgPrice = (await db.get("SELECT AVG(price / NULLIF(num_bales,0)) AS p FROM deliveries"))?.p || 0;
    const openedCount = (await db.get("SELECT COUNT(*) AS c FROM bales WHERE open_date >= date('now','-30 day')"))?.c || 0;
    const rate = openedCount / 30;
    const remaining = (await db.get("SELECT COUNT(*) AS c FROM bales WHERE open_date IS NULL"))?.c || 0;

    const forecast = [];
    const today = new Date();
    for (let m = 1; m <= 6; m++) {
        const used = rate * 30 * m;
        const cost = used * avgPrice;
        const month = new Date(today.getFullYear(), today.getMonth() + m, 1).toISOString().slice(0, 7);
        forecast.push({ month, bales_used: Math.round(used), estimated_cost: cost.toFixed(2) });
    }

    res.json({ success: true, avg_price: avgPrice.toFixed(2), daily_rate: rate.toFixed(2), forecast, remaining });
});

// -- Static uploads --
app.use("/uploads", express.static(uploadDir));

app.listen(4000, () => console.log("✅ Backend körs på http://localhost:4000"));
