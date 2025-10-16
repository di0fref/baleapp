<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// SQLite connection
$db = new PDO('sqlite:' . __DIR__ . '/haybales.db');
$db->exec("PRAGMA foreign_keys = ON;");

// auto-login via cookie
if (!isset($_SESSION['user']) && isset($_COOKIE['hayuser'])) {
    $_SESSION['user'] = $_COOKIE['hayuser'];
}

// logout
if (isset($_GET['logout'])) {
    session_destroy();
    setcookie('hayuser', '', time() - 3600, '/');
    header("Location:?");
    exit;
}

// login form
if (!isset($_SESSION['user'])):
    ?>
    <!DOCTYPE html>
    <html lang="sv">
    <head>
        <meta charset="UTF-8">
        <title>Logga in – Höbalsapp</title>
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-gray-100 flex items-center justify-center h-screen">
    <div class="bg-white p-6 rounded shadow w-full max-w-sm">
        <h1 class="text-2xl font-bold mb-4 text-center">🌾 Höbalsapp</h1>
        <form method="POST" action="api.php" class="space-y-3">
            <input name="login_user" class="w-full border rounded p-2" placeholder="Användarnamn" required>
            <input type="password" name="login_pass" class="w-full border rounded p-2" placeholder="Lösenord" required>
            <button class="bg-green-600 text-white w-full rounded p-2">Logga in</button>
        </form>
    </div>
    </body>
    </html>
    <?php exit; endif;

// delivery view toggle
$dId = isset($_GET['delivery']) ? (int)$_GET['delivery'] : null;
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <title>Höbalsapp</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="assets/app.js" defer></script>
</head>

<body class="bg-gray-50 text-gray-900 dark:bg-gray-900 dark:text-gray-100 transition-colors duration-500">
<div class="max-w-6xl mx-auto p-4">

    <!-- Header -->
    <div class="flex justify-between items-center mb-4">
        <h1 class="text-2xl font-bold">🌾 Höbalsapp</h1>
        <div class="flex items-center gap-2">
            <button id="reportBtn" class="bg-indigo-600 text-white text-sm px-3 py-1 rounded">📊 Rapport</button>
            <button id="forecastBtn" class="bg-yellow-600 text-white text-sm px-3 py-1 rounded">💰 Prognos</button>
            <button id="themeToggle" class="bg-gray-200 dark:bg-gray-700 px-3 py-1 rounded">🌙</button>
            <a href="?logout" class="bg-red-600 hover:bg-red-700 text-white text-sm px-3 py-1 rounded">Logga ut</a>
        </div>
    </div>

    <?php if (!$dId): ?>

        <!-- Overview -->
        <div id="notificationsMount"></div>
        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow mb-6">
            <h2 class="text-xl font-semibold mb-2">Leveranser</h2>

            <!-- Add delivery -->
            <form id="addDeliveryForm" class="grid grid-cols-1 md:grid-cols-4 gap-2 mb-4">
                <input name="supplier" class="border rounded p-2 dark:bg-gray-700" placeholder="Leverantör" required>
                <input type="date" name="date" class="border rounded p-2 dark:bg-gray-700" required>
                <input type="number" name="bales" class="border rounded p-2 dark:bg-gray-700" placeholder="Antal balar" min="1" required>
                <button class="bg-green-600 text-white rounded p-2">Lägg till</button>
            </form>

            <!-- Table -->
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
                    $open = count(array_filter($r, fn($x)=>$x['status']=='open'));
                    $bad = count(array_filter($r, fn($x)=>$x['is_bad']));
                    $badUnr = count(array_filter($r, fn($x)=>$x['is_bad']&&!$x['is_reimbursed']));
                    $bCol = $badUnr ? 'border-red-400' : 'border-gray-200';
                    ?>
                    <tr class="border-t border-l-4 <?= $bCol ?> dark:border-gray-600">
                        <td class="p-2"><?= htmlspecialchars($d['supplier']) ?></td>
                        <td class="p-2"><?= $d['delivery_date'] ?></td>
                        <td class="p-2 text-center"><?= $tot ?></td>
                        <td class="p-2 text-center"><?= $open ?> öppna / <?= $bad ?> felaktiga</td>
                        <td class="p-2 text-center">
                            <input type="checkbox" <?= $d['paid']?'checked':'' ?> onchange="updateDelivery(<?= $d['id'] ?>,this.checked?1:0)">
                        </td>
                        <td class="p-2 text-center">
                            <?php if ($d['invoice_file']): ?>
                                <a href="<?= $d['invoice_file'] ?>" target="_blank" class="text-blue-600 underline">Visa</a>
                                <button onclick="deleteInvoice(<?= $d['id'] ?>)" class="text-xs text-red-600">🗑️</button>
                            <?php else: ?>
                                <button onclick="uploadInvoice(<?= $d['id'] ?>)" class="text-sm bg-gray-200 px-2 py-1 rounded dark:bg-blue-700">📎 Ladda upp</button>
                            <?php endif; ?>
                        </td>
                        <td class="p-2 text-center"><a href="?delivery=<?= $d['id'] ?>" class="text-blue-600 hover:underline">Visa →</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <a href="#addDeliveryForm" class="fixed bottom-5 right-5 bg-green-600 text-white rounded-full w-14 h-14 flex items-center justify-center text-3xl shadow-lg md:hidden">+</a>

    <?php else: ?>

        <!-- Delivery details -->
        <?php
        $d = $db->query("SELECT * FROM deliveries WHERE id=$dId")->fetch(PDO::FETCH_ASSOC);
        $b = $db->query("SELECT * FROM bales WHERE delivery_id=$dId ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $tot = count($b);
        $open = count(array_filter($b, fn($x)=>$x['status']=='open'));
        $closed = count(array_filter($b, fn($x)=>$x['status']=='closed'));
        $bad = count(array_filter($b, fn($x)=>$x['is_bad']));
        ?>
        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow mb-6">
            <a href="?" class="text-blue-600 hover:underline mb-2 block">&larr; Tillbaka</a>

            <div class="bg-gray-100 dark:bg-gray-700 p-4 rounded mb-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <p><b>Leverantör:</b> <?= htmlspecialchars($d['supplier']) ?></p>
                    <p><b>Datum:</b> <?= htmlspecialchars($d['delivery_date']) ?></p>
                    <p><b>Antal balar:</b> <?= $d['num_bales'] ?></p>
                </div>
                <div>
                    <p><b>Pris:</b>
                        <span class="editable-num cursor-pointer text-blue-600" data-id="<?=$d['id']?>" data-field="price"><?= number_format($d['price'],2) ?></span> kr
                    </p>
                    <p><b>Vikt:</b>
                        <span class="editable-num cursor-pointer text-blue-600" data-id="<?=$d['id']?>" data-field="weight"><?= number_format($d['weight'],1) ?></span> kg
                    </p>
                    <p><b>Betald:</b>
                        <input type="checkbox" <?= $d['paid']?'checked':'' ?> onchange="updateDelivery(<?= $d['id'] ?>,this.checked?1:0)">
                    </p>
                </div>
                <div>
                    <p><b>Faktura:</b>
                        <?php if ($d['invoice_file']): ?>
                            <a href="<?= $d['invoice_file'] ?>" target="_blank" class="text-blue-600 underline">Visa</a>
                            <button onclick="deleteInvoice(<?= $d['id'] ?>)" class="text-xs text-red-600">🗑️</button>
                        <?php else: ?>
                            <button onclick="uploadInvoice(<?= $d['id'] ?>)" class="text-sm bg-gray-200 px-2 py-1 rounded dark:bg-blue-700">📎 Ladda upp</button>
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
                </tr>
                </thead>
                <tbody>
                <?php foreach ($b as $x):
                    $days = '-';
                    if ($x['open_date']) {
                        $d1 = new DateTime($x['open_date']);
                        $d2 = $x['close_date'] ? new DateTime($x['close_date']) : new DateTime();
                        $days = $d1->diff($d2)->days . ' dagar';
                    }
                    ?>
                    <tr class="border-t dark:border-gray-600">
                        <td class="p-2"><?= $x['id'] ?></td>
                        <td class="p-2">
                            <?php if ($x['status']) {
                                echo "<span class='px-2 py-1 text-xs rounded ".($x['status']=='open'?'bg-blue-100 text-blue-800':'bg-green-100 text-green-800')."'>".($x['status']=='open'?'Öppen':'Stängd')."</span> ";
                            }
                            if ($x['is_bad']) echo "<span class='px-2 py-1 text-xs rounded bg-red-100 text-red-800'>Felaktig</span> ";
                            if ($x['is_reimbursed']) echo "<span class='px-2 py-1 text-xs rounded bg-yellow-100 text-yellow-800'>Ersatt</span>";
                            ?>
                        </td>
                        <td class="p-2">
                            <span class="editable-date cursor-pointer text-blue-600" data-id="<?= $x['id'] ?>" data-field="open_date"><?= $x['open_date'] ?: '-' ?></span>
                        </td>
                        <td class="p-2">
                            <span class="editable-date cursor-pointer text-blue-600" data-id="<?= $x['id'] ?>" data-field="close_date"><?= $x['close_date'] ?: '-' ?></span>
                        </td>
                        <td class="p-2 text-center"><?= $days ?></td>
                        <td class="p-2 text-center">
                            <?php if ($x['photo']): ?>
                                <a href="<?= $x['photo'] ?>" target="_blank" class="text-blue-600 underline">Visa</a>
                                <button onclick="deletePhoto(<?= $x['id'] ?>)" class="text-xs text-red-600">🗑️</button>
                            <?php else: ?>
                                <span class="text-gray-400 text-xs">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="p-2 flex flex-wrap gap-1 justify-center">
                            <button onclick="setStatus(<?= $x['id'] ?>,'open')" class="px-2 py-1 text-xs bg-gray-200 rounded dark:bg-blue-700">Öppen</button>
                            <button onclick="setStatus(<?= $x['id'] ?>,'closed')" class="px-2 py-1 text-xs bg-gray-200 rounded dark:bg-blue-700">Stängd</button>
                            <button onclick="toggleFlag(<?= $x['id'] ?>,'is_bad',<?= !$x['is_bad']?1:0 ?>)" class="px-2 py-1 text-xs bg-gray-200 rounded dark:bg-blue-700">Felaktig</button>
                            <button onclick="toggleFlag(<?= $x['id'] ?>,'is_reimbursed',<?= !$x['is_reimbursed']?1:0 ?>)" class="px-2 py-1 text-xs bg-gray-200 rounded dark:bg-blue-700">Ersatt</button>
                            <button onclick="uploadPhoto(<?= $x['id'] ?>)" class="px-2 py-1 text-xs bg-gray-200 rounded dark:bg-blue-700">📷</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div id="toastContainer" class="fixed bottom-4 right-4 flex flex-col gap-2 z-50 pointer-events-none"></div>
</body>
</html>
