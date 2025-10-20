import sqlite3 from "sqlite3";
import { open } from "sqlite";

const db = await open({ filename: "./backend/db/haybales.db", driver: sqlite3.Database });

await db.exec(`
  INSERT INTO deliveries (supplier, delivery_date, num_bales, price, weight, paid)
  VALUES ('Lantbruk AB', '2025-02-10', 20, 4000, 1200, 1),
         ('Smålandshö', '2025-03-15', 15, 3200, 950, 0);
`);

const deliveries = await db.all("SELECT id, num_bales FROM deliveries");
for (const d of deliveries) {
    for (let i = 0; i < d.num_bales; i++) {
        await db.run("INSERT INTO bales (delivery_id, status) VALUES (?, ?)", [d.id, null]);
    }
}

console.log("✅ Testdata skapad!");
await db.close();
