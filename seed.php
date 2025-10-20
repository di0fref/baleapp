<?php

$db = new PDO('sqlite:' . __DIR__ . '/haybales.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Generating demo data...\n";

// Clear existing deliveries and bales
$db->exec("DELETE FROM bales");
$db->exec("DELETE FROM deliveries");

$suppliers = ['Lantmännen', 'Gröna Gården', 'AgroNord', 'Foder AB', 'Ekohö', 'Stora Fältet', 'Lillgården', 'Skog & Äng', 'Bondens Foder', 'Höcenter'];

for ($i = 1; $i <= 20; $i++) {
    $supplier = $suppliers[array_rand($suppliers)];
    $date = date('Y-m-d', strtotime("-" . rand(10, 180) . " days"));
    $num_bales = 10;
    $paid = rand(0, 1);

    $db->prepare("INSERT INTO deliveries (supplier, delivery_date, num_bales, paid) VALUES (?,?,?,?)")->execute([$supplier, $date, $num_bales, $paid]);
    $delivery_id = $db->lastInsertId();

    for ($b = 1; $b <= $num_bales; $b++) {
        $status = null;
        $is_bad = rand(0, 100) < 10 ? 1 : 0; // ~10% chance to be bad
        $is_reimbursed = $is_bad && rand(0, 1) ? 1 : 0;
        $open_date = null;
        $close_date = null;

        // Make one random bale per delivery open
        if ($b === rand(1, $num_bales)) {
            $status = 'open';
            $open_date = date('Y-m-d', strtotime("-" . rand(1, 7) . " days"));
        }

        $stmt = $db->prepare("INSERT INTO bales (delivery_id, status, is_bad, is_reimbursed, open_date, close_date) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$delivery_id, $status, $is_bad, $is_reimbursed, $open_date, $close_date]);
    }
}

echo "✅ 20 deliveries and 200 bales created.\n";
