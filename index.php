<?php session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Höbalsapp v1.1 – flera admin, bilder, anteckningar, smarta notifieringar, mörkt läge
$db = new PDO('sqlite:' . __DIR__ . '/haybales.db');

// === Enkel automatisk migration ===
$db->exec("PRAGMA foreign_keys = ON;");

function ensureColumn($db, $table, $column, $definition)
{
    $cols = array_column($db->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array($column, $cols)) {
        $db->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    }
}

// leveranser
$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='deliveries'")->fetch();
if (!$tables) {
    $db->exec("CREATE TABLE deliveries(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    supplier TEXT,
    delivery_date TEXT,
    num_bales INTEGER,
    paid INTEGER DEFAULT 0,
    invoice_file TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
  )");
}

// balar
$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='bales'")->fetch();
if (!$tables) {
    $db->exec("CREATE TABLE bales(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    delivery_id INTEGER,
    status TEXT,
    is_bad INTEGER DEFAULT 0,
    is_reimbursed INTEGER DEFAULT 0,
    open_date TEXT,
    close_date TEXT,
    reimbursed_date TEXT,
    photo TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(delivery_id) REFERENCES deliveries(id)
  )");
}

// användare
$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
if (!$tables) {
    $db->exec("CREATE TABLE users(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE,
    password TEXT
  )");
}

// kolla saknade kolumner och lägg till om de inte finns
ensureColumn($db, 'deliveries', 'paid', 'INTEGER DEFAULT 0');
ensureColumn($db, 'deliveries', 'invoice_file', 'TEXT');
ensureColumn($db, 'deliveries', 'num_bales', 'INTEGER DEFAULT 0');
ensureColumn($db, 'bales', 'status', 'TEXT');
ensureColumn($db, 'bales', 'is_bad', 'INTEGER DEFAULT 0');
ensureColumn($db, 'bales', 'is_reimbursed', 'INTEGER DEFAULT 0');
ensureColumn($db, 'bales', 'open_date', 'TEXT');
ensureColumn($db, 'bales', 'close_date', 'TEXT');
ensureColumn($db, 'bales', 'reimbursed_date', 'TEXT');
ensureColumn($db, 'bales', 'photo', 'TEXT');
ensureColumn($db, 'bales', 'opened_by', 'TEXT');
ensureColumn($db, 'bales', 'closed_by', 'TEXT');
ensureColumn($db, 'bales', 'marked_bad_by', 'TEXT');
ensureColumn($db, 'deliveries', 'price', 'REAL DEFAULT 0');
ensureColumn($db, 'deliveries', 'weight', 'REAL DEFAULT 0');

$uCount = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();

if (!$uCount) {
    $db->exec("INSERT INTO users(username,password)VALUES('Erika','Erika')");
    $db->exec("INSERT INTO users(username,password)VALUES('Fredrik','Fredrik')");
}
if (!isset($_SESSION['user']) && isset($_COOKIE['hayuser'])) {
    $_SESSION['user'] = $_COOKIE['hayuser'];
}
if (isset($_POST['login_user'], $_POST['login_pass'])) {
    $u = $db->prepare("SELECT * FROM users WHERE username=? AND password=?");
    $u->execute([$_POST['login_user'], $_POST['login_pass']]);
    $usr = $u->fetch(PDO::FETCH_ASSOC);
    if ($usr) {
        $_SESSION['user'] = $usr['username'];
        setcookie('hayuser', $usr['username'], time() + 86400 * 30, "/");
        header("Location:?");
        exit;
    } else {
        $login_error = "Fel användarnamn eller lösenord.";
    }
}
if (isset($_GET['logout'])) {
    session_destroy();
    setcookie('hayuser', '', time() - 3600, '/');
    header("Location:?");
    exit;
}
if (!isset($_SESSION['user'])):?>
    <!DOCTYPE html>
    <html lang="sv">
<head>
    <meta charset="UTF-8">
    <title>Logga in - Höbalsapp</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
        }
    </script>
</head>
<body class="bg-gray-100 flex items-center justify-center h-screen">
<div class="bg-white p-6 rounded shadow w-full max-w-sm"><h1 class="text-2xl font-bold mb-4 text-center">🌾 Höbalsapp</h1><?php if (!empty($login_error)): ?><p class="text-red-600 text-center mb-2"><?= $login_error ?></p><?php endif; ?>
    <form method="POST" class="space-y-3"><input name="login_user" class="w-full border rounded p-2" placeholder="Användarnamn" required><input type="password" name="login_pass" class="w-full border rounded p-2" placeholder="Lösenord" required>
        <button class="bg-green-600 text-white w-full rounded p-2">Logga in</button>
    </form>
</div>
</body></html><?php exit;endif;
/* ====== AJAX API ====== */
if (isset($_POST['action'])) {
    $a = $_POST['action'];

    if ($a === 'predict_costs') {
        // Genomsnittligt pris per bal (pris / antal)
        $avgPrice = $db->query("
        SELECT AVG(price / NULLIF(num_bales,0)) 
        FROM deliveries 
        WHERE num_bales > 0
    ")->fetchColumn() ?: 0;

        // Antal öppnade balar senaste 30 dagar
        $openedCount = (int)$db->query("
        SELECT COUNT(*) FROM bales 
        WHERE open_date IS NOT NULL 
          AND date(open_date) >= date('now','-30 day')
    ")->fetchColumn();

        $rate = $openedCount / 30; // balar/dag
        $remaining = (int)$db->query("SELECT COUNT(*) FROM bales WHERE open_date IS NULL")->fetchColumn();

        $forecast = [];
        $today = new DateTime();

        for ($m = 1; $m <= 6; $m++) {
            $days = 30 * $m;
            $used = $rate * $days;
            $cost = $used * $avgPrice;
            $month = (clone $today)->add(new DateInterval("P{$days}D"))->format('Y-m');
            $forecast[] = ['month' => $month, 'bales_used' => round($used), 'estimated_cost' => round($cost, 2)];
        }

        echo json_encode([
            'success' => true,
            'avg_price' => round($avgPrice, 2),
            'daily_rate' => round($rate, 2),
            'forecast' => $forecast,
            'remaining' => $remaining
        ]);
        exit;
    }

    if ($a === 'update_delivery_field') {
        $id = (int)$_POST['id'];
        $field = $_POST['field'];
        $value = $_POST['value'];
        if (!in_array($field, ['price', 'weight'])) exit;
        $db->prepare("UPDATE deliveries SET $field=? WHERE id=?")->execute([$value, $id]);
        echo json_encode(['success'=>true]);
        exit;
    }



    if ($a === 'add_delivery') {
        $st = $db->prepare("INSERT INTO deliveries(supplier,delivery_date,num_bales)VALUES(?,?,?)");
        $st->execute([$_POST['supplier'], $_POST['date'], $_POST['bales']]);
        $did = $db->lastInsertId();
        $ins = $db->prepare("INSERT INTO bales(delivery_id)VALUES(?)");
        for ($i = 0; $i < intval($_POST['bales']); $i++) {
            $ins->execute([$did]);
        }
        echo json_encode(['success' => true]);
        exit;
    }
    if ($a === 'update_delivery') {
        $db->prepare("UPDATE deliveries SET paid=? WHERE id=?")->execute([$_POST['paid'], $_POST['id']]);
        echo json_encode(['success' => true]);
        exit;
    }
    if ($a === 'upload_invoice_file') {
        $fn = time() . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $_FILES['invoice_file']['name']);
        $dest = __DIR__ . "/uploads/invoices/" . $fn;
        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0775, true);
        }
        move_uploaded_file($_FILES['invoice_file']['tmp_name'], $dest);
        $p = "uploads/invoices/" . $fn;
        $db->prepare("UPDATE deliveries SET invoice_file=? WHERE id=?")->execute([$p, $_POST['id']]);
        echo json_encode(['success' => true, 'file' => $p]);
        exit;
    }
    if ($a === 'delete_invoice_file') {
        $id = (int)$_POST['id'];
        $p = $db->query("SELECT invoice_file FROM deliveries WHERE id=$id")->fetchColumn();
        if ($p && file_exists(__DIR__ . '/' . $p)) {
            unlink(__DIR__ . '/' . $p);
        }
        $db->prepare("UPDATE deliveries SET invoice_file=NULL WHERE id=?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }
    $user = $_SESSION['user'] ?? 'okänd';

    if ($a === 'update_bale_status') {
        $id = (int)$_POST['id'];
        $s = $_POST['status'];
        if ($s === 'open')
            $db->prepare("UPDATE bales SET status='open', open_date=COALESCE(open_date,date('now')), closed_by=null, close_date=NULL, opened_by=? WHERE id=?")->execute([$user, $id]);
        elseif ($s === 'closed')
            $db->prepare("UPDATE bales SET status='closed', close_date=COALESCE(close_date,date('now')), closed_by=? WHERE id=?")->execute([$user, $id]);
        else
            $db->prepare("UPDATE bales SET status=NULL, open_date=NULL, close_date=NULL WHERE id=?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($a === 'toggle_flag') {
        $id = (int)$_POST['id'];
        $flag = $_POST['flag'];
        $value = (int)$_POST['value'];
        $user = $_SESSION['user'] ?? 'okänd';

        if ($flag === 'is_bad') {
            if ($value) {
                // Markera som felaktig – rensa status & datum
                $db->prepare("UPDATE bales SET is_bad=1, marked_bad_by=?, status=NULL, open_date=NULL, close_date=NULL WHERE id=?")
                    ->execute([$user, $id]);
            } else {
                // Återställ felaktig
                $db->prepare("UPDATE bales SET is_bad=0, marked_bad_by=NULL WHERE id=?")
                    ->execute([$id]);
            }
        } elseif ($flag === 'is_reimbursed') {
            if ($value) {
                // Markera som ersatt
                $db->prepare("UPDATE bales SET is_reimbursed=1, reimbursed_date=date('now') WHERE id=?")
                    ->execute([$id]);
            } else {
                // Ta bort ersättning
                $db->prepare("UPDATE bales SET is_reimbursed=0, reimbursed_date=NULL WHERE id=?")
                    ->execute([$id]);
            }
        }

        echo json_encode(['success' => true]);
        exit;
    }


    if ($a === 'update_date') {
        $id = (int)$_POST['id'];
        $f = $_POST['field'];
        $v = $_POST['value'] ?: null;
        if (!in_array($f, ['open_date', 'close_date'])) {
            exit;
        }
        $st = $db->query("SELECT status FROM bales WHERE id=$id")->fetchColumn();
        if (!in_array($st, ['open', 'closed'])) {
            echo json_encode(['success' => false]);
            exit;
        }
        $db->prepare("UPDATE bales SET $f=? WHERE id=?")->execute([$v, $id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($a === 'upload_photo') {
        $id = (int)$_POST['id'];
        $f = $_FILES['photo'];
        if (!$f || $f['error']) {
            echo json_encode(['success' => false]);
            exit;
        }
        $fn = time() . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $f['name']);
        $dest = __DIR__ . "/uploads/bale_photos/" . $fn;
        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0775, true);
        }
        move_uploaded_file($f['tmp_name'], $dest);
        $p = "uploads/bale_photos/" . $fn;
        $db->prepare("UPDATE bales SET photo=? WHERE id=?")->execute([$p, $id]);
        echo json_encode(['success' => true, 'path' => $p]);
        exit;
    }
    if ($a === 'check_notifications') {
        $m = (int)date('n');
        $limit = ($m >= 5 && $m <= 8) ? 5 : 7;
        $alerts = [];
        $alerts['open_long'] = $db->query("SELECT b.id,d.supplier,b.open_date FROM bales b JOIN deliveries d ON d.id=b.delivery_id WHERE b.status='open' AND b.open_date<=date('now','-{$limit} day')")->fetchAll(PDO::FETCH_ASSOC);
        $alerts['unpaid'] = $db->query("SELECT * FROM deliveries WHERE paid=0 AND delivery_date<=date('now','-30 day')")->fetchAll(PDO::FETCH_ASSOC);
        $recent = $db->query("SELECT COUNT(*) FROM deliveries WHERE delivery_date>=date('now','-30 day')")->fetchColumn();
        $alerts['no_recent'] = $recent ? [] : [['msg' => 'Ingen leverans de senaste 30 dagarna']];
        $u = $db->query("SELECT COUNT(*) FROM bales WHERE open_date IS NULL")->fetchColumn();
        $alerts['low_stock'] = $u < 3 ? [['remaining' => $u]] : [];
        echo json_encode(['success' => true, 'limitDays' => $limit, 'alerts' => $alerts]);
        exit;
    }
    if ($a === 'generate_full_report') {
        $avgQ = $db->query("SELECT open_date,close_date FROM bales WHERE open_date IS NOT NULL AND close_date IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
        $totDays = 0;
        $count = 0;
        foreach ($avgQ as $x) {
            $totDays += (new DateTime($x['open_date']))->diff(new DateTime($x['close_date']))->days;
            $count++;
        }
        $avg = $count ? round($totDays / $count, 1) : 0;

        $bad = $db->query("SELECT b.id AS bale_id,d.delivery_date,d.supplier FROM bales b JOIN deliveries d ON d.id=b.delivery_id WHERE b.is_bad=1 AND b.is_reimbursed=0 ORDER BY d.delivery_date DESC")->fetchAll(PDO::FETCH_ASSOC);

        $period = 30;
        $opened = $db->query("SELECT open_date FROM bales WHERE open_date IS NOT NULL AND date(open_date)>=date('now','-{$period} day')")->fetchAll(PDO::FETCH_ASSOC);
        $openCount = count($opened);
        $rate = $openCount / max(1, $period);
        $remaining = (int)$db->query("SELECT COUNT(*) FROM bales WHERE open_date IS NULL")->fetchColumn();
        $daysLeft = $rate > 0 ? round($remaining / $rate, 1) : null;
        $forecastDate = $daysLeft ? (new DateTime())->add(new DateInterval('P' . ceil($daysLeft) . 'D'))->format('Y-m-d') : null;

        echo json_encode([
            'success' => true,
            'avgDays' => $avg,
            'bad' => $bad,
            'period' => $period,
            'openedCount' => $openCount,
            'dailyRate' => round($rate, 2),
            'remaining' => $remaining,
            'daysLeft' => $daysLeft,
            'forecastDate' => $forecastDate
        ]);
        exit;
    }
    if ($a === 'delete_photo') {
        $id = (int)$_POST['id'];
        $photo = $db->query("SELECT photo FROM bales WHERE id=$id")->fetchColumn();
        if ($photo && file_exists(__DIR__ . '/' . $photo)) {
            unlink(__DIR__ . '/' . $photo);
        }
        $db->prepare("UPDATE bales SET photo=NULL WHERE id=?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'msg' => 'Unknown']);
    exit;
}

/* ====== PAGE ====== */
$dId = isset($_GET['delivery']) ? (int)$_GET['delivery'] : null; ?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <title>Höbalsapp</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
        }
    </script>

    <meta name="viewport" content="width=device-width,initial-scale=1">
</head>
<body class="bg-gray-50 text-gray-900 transition-colors duration-500 dark:bg-gray-900 dark:text-gray-100">
<div class="max-w-6xl mx-auto p-4">
    <div class="flex justify-between items-center mb-4">
        <h1 class="text-2xl font-bold">🌾 Höbalsapp</h1>
        <div class="flex items-center gap-2">
            <button onclick="generateReport()" class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm px-3 py-1 rounded">📊 Rapport</button>
            <button onclick="showCostPrediction()" class="bg-yellow-600 hover:bg-yellow-700 text-white text-sm px-3 py-1 rounded">💰 Prognos</button>

            <button id="themeToggle" class="bg-gray-200 dark:bg-gray-700 px-3 py-1 rounded">🌙</button>
            <a href="?logout" class="bg-red-600 hover:bg-red-700 text-white text-sm px-3 py-1 rounded">Logga ut</a>
        </div>
    </div>

    <?php if (!$dId): ?>
        <div id="notificationsMount"></div>
        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow mb-6">
            <div class="flex justify-between items-center"><h2 class="text-xl font-semibold mb-2">Leveranser</h2></div>
            <form id="addDeliveryForm" class="grid grid-cols-1 md:grid-cols-4 gap-2 mb-4">
                <input name="supplier" class="border rounded p-2 dark:bg-gray-700" placeholder="Leverantör" required>
                <input type="date" name="date" class="border rounded p-2 dark:bg-gray-700" required>
                <input type="number" name="bales" class="border rounded p-2 dark:bg-gray-700" placeholder="Antal balar" min="1" required>
                <button class="bg-green-600 text-white rounded p-2">Lägg till</button>
            </form>
            <table class="min-w-full text-sm border dark:border-gray-700">
                <thead class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-100 uppercase">
                <tr>
                    <th class="p-2">Leverantör</th>
                    <th class="p-2">Datum</th>
                    <th class="p-2">Antal</th>
                    <th class="p-2">Status</th>
                    <th class="p-2">Betald</th>
                    <th class="p-2">Faktura</th>
                    <th class="p-2"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($db->query("SELECT * FROM deliveries ORDER BY id DESC") as $d):
                    $r = $db->query("SELECT status,is_bad,is_reimbursed FROM bales WHERE delivery_id={$d['id']}")->fetchAll(PDO::FETCH_ASSOC);
                    $tot = count($r);
                    $open = count(array_filter($r, fn($x) => $x['status'] == 'open'));
                    $bad = count(array_filter($r, fn($x) => $x['is_bad']));
                    $badUnr = count(array_filter($r, fn($x) => $x['is_bad'] && !$x['is_reimbursed']));
                    $bCol = $badUnr ? 'border-red-400' : 'border-gray-200'; ?>
                <tr class="border-t border-l-4 <?= $bCol ?> dark:border-gray-600">
                    <td class="p-2"><?= htmlspecialchars($d['supplier']) ?></td>
                    <td class="p-2"><?= $d['delivery_date'] ?></td>
                    <td class="p-2 text-center"><?= $tot ?></td>
                    <td class="p-2 text-center"><?= $open ?> öppna / <?= $bad ?> felaktiga</td>
                    <td class="p-2 text-center"><input type="checkbox" <?= $d['paid'] ? 'checked' : '' ?> onchange="updateDelivery(<?= $d['id'] ?>,this.checked?1:0)"></td>
                    <td class="p-2 text-center"><?php if ($d['invoice_file']): ?><a href="<?= $d['invoice_file'] ?>" target="_blank" class="text-blue-600 underline">Visa</a>
                        <button onclick="deleteInvoice(<?= $d['id'] ?>)" class="text-xs text-red-600">🗑️</button><?php else: ?>
                        <button onclick="uploadInvoice(<?= $d['id'] ?>)" class="text-sm bg-gray-200 px-2 py-1 rounded dark:bg-blue-700 dark:hover:bg-blue-600">📎 Ladda upp</button><?php endif; ?></td>
                    <td class="p-2 text-center"><a href="?delivery=<?= $d['id'] ?>" class="text-blue-600 hover:underline">Visa →</a></td></tr><?php endforeach; ?></tbody>
            </table>
        </div>
        <a href="#addDeliveryForm" class="fixed bottom-5 right-5 bg-green-600 text-white rounded-full w-14 h-14 flex items-center justify-center text-3xl shadow-lg md:hidden">+</a>
    <?php else:
        $d = $db->query("SELECT * FROM deliveries WHERE id=$dId")->fetch(PDO::FETCH_ASSOC);
        $b = $db->query("SELECT * FROM bales WHERE delivery_id=$dId ORDER BY CASE WHEN open_date IS NULL THEN 1 ELSE 0 END,date(open_date) ASC,id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $tot = count($b);
        $open = count(array_filter($b, fn($x) => $x['status'] == 'open'));
        $closed = count(array_filter($b, fn($x) => $x['status'] == 'closed'));
        $bad = count(array_filter($b, fn($x) => $x['is_bad'])); ?>
        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow mb-6">





        <div class="my-2">
        <a href="?" class="text-blue-600 hover:underline">&larr; Tillbaka</a>
        </div>
<!--        <h2 class="text-xl font-semibold mb-2 mt-2">Balar för --><?php //= htmlspecialchars($d['supplier']) ?><!-- (--><?php //= $d['delivery_date'] ?><!--)</h2>-->

        <div class="bg-gray-100 dark:bg-gray-700 p-4 rounded mb-4 grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <p><b>Leverantör:</b> <?= htmlspecialchars($d['supplier']) ?></p>
                <p><b>Datum:</b> <?= htmlspecialchars($d['delivery_date']) ?></p>
                <p><b>Antal balar:</b> <?= $d['num_bales'] ?></p>
            </div>
            <div>
                <p><b>Pris:</b>
                    <span class="editable-num cursor-pointer text-blue-600" data-id="<?=$d['id']?>" data-field="price">
        <?= number_format($d['price'], 2) ?>
      </span> kr
                </p>
                <p><b>Vikt:</b>
                    <span class="editable-num cursor-pointer text-blue-600" data-id="<?=$d['id']?>" data-field="weight">
        <?= number_format($d['weight'], 1) ?>
      </span> kg
                </p>
                <p><b>Betald:</b>
                    <input type="checkbox" <?= $d['paid'] ? 'checked' : '' ?> onchange="updateDelivery(<?= $d['id'] ?>,this.checked?1:0)">
                </p>
            </div>
            <div>
                <p><b>Faktura:</b>
                    <?php if ($d['invoice_file']): ?>
                        <a href="<?= $d['invoice_file'] ?>" target="_blank" class="text-blue-600 underline">Visa</a>
                        <button onclick="deleteInvoice(<?= $d['id'] ?>)" class="text-xs text-red-600">🗑️</button>
                    <?php else: ?>
                        <button onclick="uploadInvoice(<?= $d['id'] ?>)" class="text-sm bg-gray-200 px-2 py-1 rounded dark:bg-blue-700 dark:hover:bg-blue-600">📎 Ladda upp</button>
                    <?php endif; ?>
                </p>
                <p><b>Skapad:</b> <?= $d['created_at'] ?></p>
            </div>
        </div>

        <p class="mb-2 text-sm">Totalt: <?= $tot ?> • Öppna: <?= $open ?> • Stängda: <?= $closed ?> • Felaktiga: <?= $bad ?></p>


        <table class="min-w-full text-sm border dark:border-gray-700">
            <thead class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-100 uppercase">
            <tr>
                <th class="p-2">#</th>
                <th class="p-2">Status</th>
                <th class="p-2">Öppnad</th>
                <th class="p-2">Stängd</th>
                <th class="p-2">Dagar</th>
                <th class="p-2">Bild</th>
                <th class="p-2">Åtgärder</th>

            </thead>
            <tbody>
            <?php foreach ($b as $x):$days = '-';
                if ($x['open_date']) {
                    $d1 = new DateTime($x['open_date']);
                    $d2 = $x['close_date'] ? new DateTime($x['close_date']) : new DateTime();
                    $days = $d1->diff($d2)->days . ' dagar';
                } ?>
                <tr class="border-t dark:border-gray-600">
                <td class="p-2"><?= $x['id'] ?></td>
                <td class="p-2"><?php if ($x['status']) {
                        echo "<span class='px-2 py-1 text-xs rounded " . ($x['status'] == 'open' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800') . "'>" . ($x['status'] == 'open' ? 'Öppen' : 'Stängd') . "</span> ";
                    }
                    if ($x['is_bad']) {
                        echo "<span class='px-2 py-1 text-xs rounded bg-red-100 text-red-800'>Felaktig</span> ";
                    }
                    if ($x['is_reimbursed']) {
                        echo "<span class='px-2 py-1 text-xs rounded bg-yellow-100 text-yellow-800'>Ersatt</span> ";
                    } ?></td>

                <td class="p-2">
                    <div class="flex items-center gap-1">
    <span class="editable-date _text-blue-700 _underline cursor-pointer"
          data-id="<?= $x['id'] ?>" data-field="open_date"
          data-locked="<?= ($x['status'] == 'open' || $x['status'] == 'closed') ? 'false' : 'true' ?>">
      <?= $x['open_date'] ?: '-' ?>
    </span>
                        <?php if ($x['opened_by']): ?>
                            <span class="text-xs text-gray-500 italic">(av <?= $x['opened_by'] ?>)</span>
                        <?php endif; ?>
                    </div>
                </td>

                <td class="p-2">
                    <div class="flex items-center gap-1">
    <span class="editable-date _text-blue-700 _underline cursor-pointer"
          data-id="<?= $x['id'] ?>" data-field="close_date"
          data-locked="<?= ($x['status'] == 'open' || $x['status'] == 'closed') ? 'false' : 'true' ?>">
      <?= $x['close_date'] ?: '-' ?>
    </span>
                        <?php if ($x['closed_by']): ?>
                            <span class="text-xs text-gray-500 italic">(av <?= $x['closed_by'] ?>)</span>
                        <?php endif; ?>
                    </div>
                </td>


                <td class="p-2 text-center"><?= $days ?></td>
                <td class="p-2 text-center">
                    <?php if (!empty($x['photo'])): ?>
                        <div class="flex flex-row items-center gap-1">
                            <a href="<?= $x['photo'] ?>" target="_blank" class="text-blue-600 _text-xs underline">Visa bild</a>

                            <button onclick="deletePhoto(<?= $x['id'] ?>)"
                                    class="text-red-600 hover:text-red-800 text-sm"
                                    title="Ta bort bild">🗑️
                            </button>

                        </div>
                    <?php else: ?>
                        <span class="text-gray-400 text-xs">-</span>
                    <?php endif; ?>

                </td>
                <td class="p-2 flex flex-wrap gap-1 justify-center">
                    <button onclick="setStatus(<?= $x['id'] ?>,'open')" class="px-2 py-1 border_ rounded text-xs bg-gray-200 dark:bg-blue-700 dark:hover:bg-blue-600">Öppen</button>
                    <button onclick="setStatus(<?= $x['id'] ?>,'closed')" class="px-2 py-1 border_ rounded text-xs bg-gray-200 dark:bg-blue-700 dark:hover:bg-blue-600">Stängd</button>
                    <button onclick="toggleFlag(<?= $x['id'] ?>,'is_bad',<?= !$x['is_bad'] ? 1 : 0 ?>)" class="px-2 py-1 border_ rounded text-xs bg-gray-200 dark:bg-blue-700 dark:hover:bg-blue-600">Felaktig</button>
                    <button onclick="toggleFlag(<?= $x['id'] ?>,'is_reimbursed',<?= !$x['is_reimbursed'] ? 1 : 0 ?>)" class="px-2 py-1 _border rounded text-xs bg-gray-200 dark:bg-blue-700 dark:hover:bg-blue-600">Ersatt</button>
                    <button onclick="uploadPhoto(<?= $x['id'] ?>)" class="px-2 py-1 border_ rounded text-xs bg-gray-200 dark:bg-blue-700 dark:hover:bg-blue-600">📷</button>

                </td>



                </tr><?php endforeach; ?></tbody>
        </table></div><?php endif; ?></div>
<div id="toastContainer" class="fixed bottom-4 right-4 flex flex-col gap-2 z-50 pointer-events-none"></div>

<script>
    const tc = document.getElementById('toastContainer');

    async function showCostPrediction(){
        const fd=new FormData();
        fd.append('action','predict_costs');
        const r=await fetch('',{method:'POST',body:fd});
        const j=await r.json();
        if(!j.success){showToast('Kunde inte hämta prognos','error');return;}

        let html=`<h3 class='text-xl font-semibold mb-2'>💰 Kostnadsprognos (6 månader)</h3>`;
        html+=`<p><b>Genomsnittligt pris per bal:</b> ${j.avg_price} kr</p>`;
        html+=`<p><b>Förbrukningstakt:</b> ${j.daily_rate} balar/dag</p>`;
        html+=`<p><b>Kvar i lager:</b> ${j.remaining} balar</p>`;
        html+=`<hr class='my-2'><table class='w-full text-sm border border-gray-300 dark:border-gray-700'><thead class='bg-gray-200 dark:bg-gray-800'><tr><th class='p-2'>Månad</th><th class='p-2'>Förväntade balar</th><th class='p-2'>Beräknad kostnad</th></tr></thead><tbody>`;
        j.forecast.forEach(f=>{
            html+=`<tr class='border-t dark:border-gray-700'><td class='p-2'>${f.month}</td><td class='p-2'>${f.bales_used}</td><td class='p-2'>${f.estimated_cost} kr</td></tr>`;
        });
        html+='</tbody></table>';
        showModal(html);
    }

    function showToast(m, t = 'success', d = 3000) {
        const c = {success: 'bg-green-600', error: 'bg-red-600', info: 'bg-blue-600'};
        const e = document.createElement('div');
        e.className = (c[t] || c.success) + " text-white px-4 py-2 rounded shadow-md opacity-0 transition-all duration-300";
        e.textContent = m;
        tc.appendChild(e);
        setTimeout(() => e.style.opacity = 1, 50);
        setTimeout(() => {
            e.style.opacity = 0;
            setTimeout(() => e.remove(), 300)
        }, d);
    }

    const toggle = document.getElementById('themeToggle');

    function updateTheme() {
        if (localStorage.theme === 'dark') {
            document.documentElement.classList.add('dark');
            toggle.textContent = '☀️';
        } else {
            document.documentElement.classList.remove('dark');
            toggle.textContent = '🌙';
        }
    }

    updateTheme();
    toggle.addEventListener('click', () => {
        localStorage.theme = localStorage.theme === 'dark' ? 'light' : 'dark';
        updateTheme();
    });


    document.getElementById('addDeliveryForm')?.addEventListener('submit', async e => {
        e.preventDefault();
        const f = new FormData(e.target);
        f.append('action', 'add_delivery');
        const r = await fetch('', {method: 'POST', body: f});
        const j = await r.json();
        if (j.success) location.reload();
    });

    async function updateDelivery(i, p) {
        const f = new FormData();
        f.append('action', 'update_delivery');
        f.append('id', i);
        f.append('paid', p);
        await fetch('', {method: 'POST', body: f});
        showToast('💾 Sparat');
    }

    async function uploadInvoice(i) {
        const inp = document.createElement('input');
        inp.type = 'file';
        inp.accept = 'application/pdf';
        inp.onchange = async () => {
            const f = inp.files[0];
            if (!f) return;
            const fd = new FormData();
            fd.append('action', 'upload_invoice_file');
            fd.append('id', i);
            fd.append('invoice_file', f);
            const r = await fetch('', {method: 'POST', body: fd});
            const j = await r.json();
            if (j.success) location.reload();
        };
        inp.click();
    }

    async function deleteInvoice(i) {
        if (!confirm('Ta bort faktura?')) return;
        const fd = new FormData();
        fd.append('action', 'delete_invoice_file');
        fd.append('id', i);
        await fetch('', {method: 'POST', body: fd});
        showToast('🗑️ Faktura borttagen');
        location.reload();
    }

    async function setStatus(i, s) {
        const fd = new FormData();
        fd.append('action', 'update_bale_status');
        fd.append('id', i);
        fd.append('status', s);
        await fetch('', {method: 'POST', body: fd});
        location.reload();
    }

    async function toggleFlag(i, f, v) {
        const fd = new FormData();
        fd.append('action', 'toggle_flag');
        fd.append('id', i);
        fd.append('flag', f);
        fd.append('value', v);
        await fetch('', {method: 'POST', body: fd});
        location.reload();
    }

    function makeDatesEditable() {
        document.querySelectorAll('.editable-date').forEach(el => {
            el.addEventListener('click', () => {
                if (el.dataset.locked === 'true') return;
                if (el.querySelector('input')) return;
                const id = el.dataset.id, f = el.dataset.field;
                const cur = el.textContent.trim();
                const i = document.createElement('input');
                i.type = 'date';
                i.className = 'border rounded p-1 text-sm w-full dark:bg-gray-700';
                i.value = cur && cur !== '-' ? cur : new Date().toISOString().split('T')[0];
                el.innerHTML = '';
                el.appendChild(i);
                i.focus();
                i.addEventListener('change', save);
                i.addEventListener('blur', save);

                async function save() {
                    const newVal = i.value.trim();
                    const oldVal = cur.trim();

                    // Do nothing if the value didn't change or is empty
                    if (!newVal || newVal === oldVal || newVal === '-') {
                        el.textContent = oldVal || '-';
                        return;
                    }

                    const fd = new FormData();
                    fd.append('action', 'update_date');
                    fd.append('id', id);
                    fd.append('field', f);
                    fd.append('value', newVal);

                    const r = await fetch('', {method: 'POST', body: fd});
                    const j = await r.json();
                    if (j.success) {
                        el.textContent = newVal;
                        showToast('📅 Datum sparat');
                    } else {
                        el.textContent = oldVal || '-';
                        showToast('⚠️ Kunde inte spara datum', 'error');
                    }
                }

            });
        });
    }

    document.addEventListener('DOMContentLoaded', makeDatesEditable);
    let noteId = null;


    async function uploadPhoto(id) {
        const i = document.createElement('input');
        i.type = 'file';
        i.accept = 'image/*';
        i.onchange = async () => {
            const f = i.files[0];
            if (!f) return;
            const fd = new FormData();
            fd.append('action', 'upload_photo');
            fd.append('id', id);
            fd.append('photo', f);
            const r = await fetch('', {method: 'POST', body: fd});
            const j = await r.json();
            if (j.success) {
                showToast('📷 Bild uppladdad');
                location.reload();
            }
        };
        i.click();
    }


    async function generateReport() {
        const fd = new FormData();
        fd.append('action', 'generate_full_report');
        const r = await fetch('', {method: 'POST', body: fd});
        const j = await r.json();
        if (!j.success) {
            showToast('Kunde inte generera rapport', 'error');
            return;
        }
        let html = `<h3 class='text-xl font-semibold mb-2'>📊 Rapport</h3>`;
        html += `<p><b>Genomsnittlig öppentid:</b> ${j.avgDays} dagar</p>`;
        html += `<p><b>Öppnade senaste ${j.period} dagarna:</b> ${j.openedCount} (${j.dailyRate}/dag)</p>`;
        html += `<p><b>Kvar i lager:</b> ${j.remaining}</p>`;
        html += `<p><b>Prognos:</b> ${j.daysLeft ? j.daysLeft + ' dagar kvar — beräknat slut ' + j.forecastDate : '-'} </p>`;
        if (j.bad.length) {
            html += `<hr class='my-2'><p><b>Felaktiga (ej ersatta):</b></p><ul class='list-disc list-inside text-sm'>`;
            j.bad.forEach(b => html += `<li>Bal #${b.bale_id} (${b.supplier}, ${b.delivery_date})</li>`);
            html += '</ul>';
        } else html += `<p class='text-sm text-gray-500 mt-2'>Inga felaktiga balar 🎉</p>`;
        showModal(html);
    }

    function showModal(content) {
        const overlay = document.createElement('div');
        overlay.className = 'fixed inset-0 bg-black/40 flex items-center justify-center z-50';
        overlay.innerHTML = `<div class='bg-white dark:bg-gray-800 p-5 rounded shadow-lg max-w-lg w-full relative'>
    <button class='absolute top-2 right-3 text-gray-400 hover:text-gray-600' onclick='this.closest(".fixed").remove()'>✖</button>
    ${content}
  </div>`;
        document.body.appendChild(overlay);
    }

    async function deletePhoto(id) {
        if (!confirm('Ta bort bilden för denna bal?')) return;
        const fd = new FormData();
        fd.append('action', 'delete_photo');
        fd.append('id', id);
        const r = await fetch('', {method: 'POST', body: fd});
        const j = await r.json();
        if (j.success) {
            showToast('🗑️ Bild borttagen');
            location.reload();
        } else {
            showToast('⚠️ Kunde inte ta bort bilden', 'error');
        }
    }


    async function checkNotifications() {
        const fd = new FormData();
        fd.append('action', 'check_notifications');
        const r = await fetch('', {method: 'POST', body: fd});
        const j = await r.json();
        const m = document.getElementById('notificationsMount');
        if (!m) return;
        m.innerHTML = '';
        if (j.alerts.open_long.length || j.alerts.unpaid.length || j.alerts.no_recent.length || j.alerts.low_stock.length) {
            let h = `<div class='bg-yellow-100 dark:bg-yellow-900 border border-yellow-400 dark:border-yellow-700 text-yellow-800 dark:text-yellow-200 p-3 rounded mb-4'><b>⚠️ Varningar</b><ul class='list-disc list-inside'>`;
            if (j.alerts.open_long.length) h += `<li>Balar öppna > ${j.limitDays} dagar</li>`;
            if (j.alerts.unpaid.length) h += `<li>${j.alerts.unpaid.length} obetalda leveranser</li>`;
            if (j.alerts.no_recent.length) h += `<li>Inga leveranser senaste 30 dagar</li>`;
            if (j.alerts.low_stock.length) h += `<li>Lågt lager (${j.alerts.low_stock[0].remaining} balar kvar)</li>`;
            h += '</ul></div>';
            m.innerHTML = h;
        } else m.innerHTML = `<p class='text-sm text-gray-600 dark:text-gray-400 mb-3'>✅ Inga varningar</p>`;
    }

    document.addEventListener('DOMContentLoaded', checkNotifications);
    setInterval(checkNotifications, 600000);

    function makeNumbersEditable(){
        document.querySelectorAll('.editable-num').forEach(el=>{
            el.addEventListener('click',()=>{
                if(el.querySelector('input')) return;
                const id=el.dataset.id, field=el.dataset.field;
                const current=el.textContent.trim();
                const inp=document.createElement('input');
                inp.type='number';
                inp.step='0.01';
                inp.value=current.replace(',','.');
                inp.className='border rounded p-1 text-sm w-24 dark:bg-gray-700';
                el.innerHTML='';
                el.appendChild(inp);
                inp.focus();
                inp.addEventListener('blur',save);
                inp.addEventListener('change',save);

                async function save(){
                    const val=inp.value.trim();
                    if(!val || val===current) {el.textContent=current; return;}
                    const fd=new FormData();
                    fd.append('action','update_delivery_field');
                    fd.append('id',id);
                    fd.append('field',field);
                    fd.append('value',val);
                    const r=await fetch('',{method:'POST',body:fd});
                    const j=await r.json();
                    if(j.success){el.textContent=val;showToast('💾 Sparat');}
                    else {el.textContent=current;showToast('⚠️ Kunde inte spara','error');}
                }
            });
        });
    }
    document.addEventListener('DOMContentLoaded',makeNumbersEditable);

</script>
</body>
</html>
