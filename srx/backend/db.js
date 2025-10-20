import sqlite3 from "sqlite3";
import { open } from "sqlite";
import path from "path";
import { fileURLToPath } from "url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DB_PATH = path.join(__dirname, "haybales.db");

let db;

/**
 * Initierar och returnerar en delad databas-instans.
 * Skapar tabeller och standardanvändare om det saknas.
 */
export async function getDb() {
    if (db) return db;

    db = await open({
        filename: DB_PATH,
        driver: sqlite3.Database
    });

    await db.exec("PRAGMA foreign_keys = ON;");

    // === Skapa tabeller om de inte finns ===
    await db.exec(`
    CREATE TABLE IF NOT EXISTS users (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      username TEXT UNIQUE,
      password TEXT
    );
  `);

    await db.exec(`
    CREATE TABLE IF NOT EXISTS deliveries (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      supplier TEXT,
      delivery_date TEXT,
      num_bales INTEGER DEFAULT 0,
      price REAL DEFAULT 0,
      weight REAL DEFAULT 0,
      paid INTEGER DEFAULT 0,
      invoice_file TEXT,
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
      reimbursed_date TEXT,
      photo TEXT,
      opened_by TEXT,
      closed_by TEXT,
      marked_bad_by TEXT,
      created_at TEXT DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY(delivery_id) REFERENCES deliveries(id)
    );
  `);

    // === Lägg till användare om inga finns ===
    const userCount = await db.get("SELECT COUNT(*) AS c FROM users");
    if (userCount.c === 0) {
        await db.run("INSERT INTO users (username, password) VALUES ('Erika','Erika')");
        await db.run("INSERT INTO users (username, password) VALUES ('Fredrik','Fredrik')");
    }

    console.log("📦 SQLite redo på", DB_PATH);
    return db;
}

/**
 * En enkel hjälpfunktion för att hämta statistik för en leverans.
 */
export async function getDeliveryStats(deliveryId) {
    const db = await getDb();
    const bales = await db.all(
        "SELECT status, is_bad, is_reimbursed FROM bales WHERE delivery_id=?",
        [deliveryId]
    );

    return {
        total: bales.length,
        open: bales.filter(b => b.status === "open").length,
        bad: bales.filter(b => b.is_bad).length,
        unreimbursed: bales.filter(b => b.is_bad && !b.is_reimbursed).length
    };
}

/**
 * Hjälpfunktion för att lägga till en leverans med balar.
 */
export async function addDelivery(supplier, delivery_date, num_bales) {
    const db = await getDb();
    const result = await db.run(
        "INSERT INTO deliveries (supplier, delivery_date, num_bales) VALUES (?,?,?)",
        [supplier, delivery_date, num_bales]
    );
    const deliveryId = result.lastID;
    for (let i = 0; i < num_bales; i++) {
        await db.run("INSERT INTO bales (delivery_id) VALUES (?)", [deliveryId]);
    }
    return deliveryId;
}
