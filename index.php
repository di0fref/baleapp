<?php
session_start();

// --- Enkel inloggning ---
$USERNAME = "admin";
$PASSWORD = "losenord"; // ändra detta!

// Automatisk inloggning via cookie
if (!isset($_SESSION['user']) && isset($_COOKIE['hayuser'])) {
    $_SESSION['user'] = $_COOKIE['hayuser'];
}

// Hantera inloggning
if (isset($_POST['login_user']) && isset($_POST['login_pass'])) {
    if ($_POST['login_user'] === $USERNAME && $_POST['login_pass'] === $PASSWORD) {
        $_SESSION['user'] = $USERNAME;
        setcookie('hayuser', $USERNAME, time() + (86400 * 30), "/");
        header("Location: ?");
        exit;
    } else {
        $login_error = "Fel användarnamn eller lösenord.";
    }
}

// Hantera utloggning
if (isset($_GET['logout'])) {
    session_destroy();
    setcookie('hayuser', '', time() - 3600, "/");
    header("Location: ?");
    exit;
}

// Visa inloggningssida om användaren inte är inloggad
if (!isset($_SESSION['user'])):
    ?>
    <!DOCTYPE html>
    <html lang="sv">
    <head>
        <meta charset="UTF-8">
        <title>Logga in - Höbalsapp</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <meta name="viewport" content="width=device-width, initial-scale=1">
    </head>
    <body class="bg-gray-100 flex items-center justify-center h-screen">
    <div class="bg-white p-6 rounded shadow w-full max-w-sm">
        <h1 class="text-2xl font-bold mb-4 text-center">🌾 Höbalsapp</h1>
        <?php if (!empty($login_error)): ?>
            <p class="text-red-600 text-center mb-2"><?=$login_error?></p>
        <?php endif; ?>
        <form method="POST" class="space-y-3">
            <input type="text" name="login_user" placeholder="Användarnamn" class="w-full border rounded p-2" required>
            <input type="password" name="login_pass" placeholder="Lösenord" class="w-full border rounded p-2" required>
            <button class="bg-green-600 text-white w-full rounded p-2">Logga in</button>
        </form>
        <p class="text-xs text-gray-500 mt-3 text-center">Du förblir inloggad automatiskt.</p>
    </div>
    </body>
    </html>
    <?php exit; endif; ?>

<?php
// --- Databas ---
$dbFile = __DIR__ . '/haybales.db';
$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Skapa tabeller vid behov
$db->exec("
CREATE TABLE IF NOT EXISTS deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    supplier TEXT NOT NULL,
    delivery_date TEXT NOT NULL,
    invoice_number TEXT,
    num_bales INTEGER DEFAULT 0,
    paid INTEGER DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS bales (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    delivery_id INTEGER NOT NULL,
    status TEXT,
    is_bad INTEGER DEFAULT 0,
    is_reimbursed INTEGER DEFAULT 0,
    open_date TEXT,
    close_date TEXT,
    reimbursed_date TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (delivery_id) REFERENCES deliveries(id)
);
");
?>

<?php
// --- AJAX-åtgärder ---
if (isset($_POST['action'])) {
    $a = $_POST['action'];

    // Lägg till leverans
    if ($a === 'add_delivery') {
        $stmt = $db->prepare("INSERT INTO deliveries (supplier, delivery_date, invoice_number, num_bales)
                              VALUES (?, ?, ?, ?)");
        $stmt->execute([$_POST['supplier'], $_POST['date'], $_POST['invoice'], $_POST['bales']]);
        $did = $db->lastInsertId();
        $ins = $db->prepare("INSERT INTO bales (delivery_id) VALUES (?)");
        for ($i = 0; $i < intval($_POST['bales']); $i++) $ins->execute([$did]);
        echo json_encode(['success' => true]);
        exit;
    }

    // Uppdatera faktura / betald
    if ($a === 'update_delivery') {
        $stmt = $db->prepare("UPDATE deliveries SET invoice_number=?, paid=? WHERE id=?");
        $stmt->execute([$_POST['invoice'], $_POST['paid'], $_POST['id']]);
        echo json_encode(['success' => true]);
        exit;
    }

    // Uppdatera datum (öppnad/stängd)
    if ($a === 'update_date') {
        $stmt = $db->prepare("UPDATE bales SET {$_POST['field']}=? WHERE id=?");
        $stmt->execute([$_POST['value'] ?: null, $_POST['id']]);
        echo json_encode(['success' => true]);
        exit;
    }

    // Uppdatera status
    if ($a === 'update_bale_status') {
        $id = $_POST['id'];
        $s = $_POST['status'];
        if ($s === 'open')
            $db->prepare("UPDATE bales SET status='open', open_date=COALESCE(open_date,date('now')), close_date=NULL WHERE id=?")->execute([$id]);
        elseif ($s === 'closed')
            $db->prepare("UPDATE bales SET status='closed', close_date=COALESCE(close_date,date('now')) WHERE id=?")->execute([$id]);
        else
            $db->prepare("UPDATE bales SET status=NULL, open_date=NULL, close_date=NULL WHERE id=?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    // Flagga felaktig / ersatt
    if ($a === 'toggle_flag') {
        $id = intval($_POST['id']);
        $flag = $_POST['flag'];
        $val = intval($_POST['value']);

        if ($flag === 'is_reimbursed') {
            if ($val)
                $db->prepare("UPDATE bales SET is_reimbursed=1, reimbursed_date=date('now') WHERE id=?")->execute([$id]);
            else
                $db->prepare("UPDATE bales SET is_reimbursed=0, reimbursed_date=NULL WHERE id=?")->execute([$id]);
        } elseif ($flag === 'is_bad') {
            if ($val) {
                // När en bal markeras som felaktig: rensa status + datum
                $db->prepare("
            UPDATE bales
            SET is_bad=1,
                status=NULL

            WHERE id=?")->execute([$id]);
            } else {
                // Avmarkera felaktig
                $db->prepare("UPDATE bales SET is_bad=0 WHERE id=?")->execute([$id]);
            }
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // 🔔 Notifieringar
    if ($a === 'check_notifications') {
        $month = (int)date('n');
        $limitDays = ($month >= 5 && $month <= 8) ? 5 : 7;
        $rows = $db->query("
            SELECT b.id, b.delivery_id, d.supplier, b.open_date
            FROM bales b
            JOIN deliveries d ON d.id = b.delivery_id
            WHERE b.status = 'open'
              AND b.open_date <= date('now', '-{$limitDays} day')
        ")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'limitDays' => $limitDays, 'alerts' => $rows]);
        exit;
    }

    // 📊 Rapport + prognos
    if ($a === 'generate_full_report') {
        $rows = $db->query("SELECT open_date, close_date FROM bales WHERE open_date IS NOT NULL AND close_date IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
        $total = 0;
        $count = 0;
        foreach ($rows as $r) {
            $total += (new DateTime($r['open_date']))->diff(new DateTime($r['close_date']))->days;
            $count++;
        }
        $avgDays = $count ? round($total / $count, 2) : 0;
        $bad = $db->query("
            SELECT b.id AS bale_id, d.delivery_date
            FROM bales b
            JOIN deliveries d ON d.id = b.delivery_id
            WHERE b.is_bad = 1 AND b.is_reimbursed = 0
            ORDER BY d.delivery_date ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // --- Prognos ---
        $periodDays = 30;

// Hur många balar har öppnats senaste 30 dagarna?
        $opened = $db->query("
    SELECT open_date FROM bales
    WHERE open_date IS NOT NULL
      AND date(open_date) >= date('now', '-{$periodDays} day')
")->fetchAll(PDO::FETCH_ASSOC);

        $openedCount = count($opened);
        $dailyRate = $openedCount / $periodDays;

// ✅ Endast balar som inte är öppnade alls räknas som "kvar i lager"
        $remaining = $db->query("
    SELECT COUNT(*) FROM bales
    WHERE open_date IS NULL
")->fetchColumn();

// Om allt är öppnat (remaining = 0), visa ingen prognos
        if ($remaining == 0) {
            $daysLeft = 0;
            $forecastDate = null;
        } else {
            $daysLeft = $dailyRate > 0 ? round($remaining / $dailyRate, 1) : null;
            $daysInt = $daysLeft ? ceil($daysLeft) : 0;
            $forecastDate = $daysInt ? (new DateTime())->add(new DateInterval("P{$daysInt}D"))->format('Y-m-d') : null;
        }

        echo json_encode([
                'success' => true,
                'avgDays' => $avgDays,
                'bad' => $bad,
                'period' => $periodDays,
                'openedCount' => $openedCount,
                'dailyRate' => round($dailyRate, 2),
                'remaining' => $remaining,
                'daysLeft' => $daysLeft,
                'forecastDate' => $forecastDate
        ]);
        exit;
    }
}








// --- Funktioner ---
function badge($text, $color)
{
    return "<span class='px-2 py-1 rounded text-xs font-semibold {$color}'>$text</span>";
}
?>

<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <title>Höbalsapp</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
		th, td { vertical-align: middle; }
    </style>
</head>
<body class="bg-gray-50 text-gray-900">
<div class="max-w-6xl mx-auto p-4">

    <!-- Header -->
    <div class="flex justify-between items-center mb-4">
        <h1 class="text-2xl font-bold">🌾 Höbalsapp</h1>
        <div class="flex items-center gap-2">
            <span class="text-sm text-gray-600">👤 <?=htmlspecialchars($_SESSION['user'])?></span>
            <a href="?logout" class="bg-red-600 hover:bg-red-700 text-white text-sm px-3 py-1 rounded">Logga ut</a>
        </div>
    </div>

    <!-- Popup-modal -->
    <div id="reportModal" class="hidden fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center z-50 transition-opacity duration-200">
        <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-5 relative">
            <button onclick="closeReport()" class="absolute top-2 right-2 text-gray-500 hover:text-gray-700 text-xl font-bold">×</button>
            <h2 class="text-xl font-semibold mb-3">📊 Rapport</h2>
            <div id="reportContent" class="text-sm text-gray-800 space-y-2">
                <p>Laddar rapport...</p>
            </div>
            <div class="mt-4 text-right">
                <button onclick="closeReport()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-1 rounded">Stäng</button>
            </div>
        </div>
    </div>

    <!-- Leveranser -->
    <?php if (!isset($_GET['delivery'])): ?>

    <div class="bg-white p-4 rounded shadow mb-6">
        <div class="flex justify-between mb-4">
            <h2 class="text-xl font-semibold">Leveranser</h2>
            <button onclick="generateReport()" class="bg-indigo-600 text-white px-4 py-2 rounded mb-4">📊 Skapa rapport</button>

        </div>

        <form id="addDeliveryForm" class="grid grid-cols-1 md:grid-cols-5 gap-2 mb-4">
            <input name="supplier" placeholder="Leverantör" class="border rounded p-2" required>
            <input type="date" name="date" class="border rounded p-2" required>
            <input name="invoice" placeholder="Fakturanummer" class="border rounded p-2">
            <input type="number" name="bales" placeholder="Antal balar" class="border rounded p-2" min="1" required>
            <button class="bg-green-600 text-white rounded p-2">Lägg till</button>
        </form>

        <table class="hidden md:table min-w-full text-sm border">
            <thead class="bg-gray-100 text-gray-700 uppercase">
            <tr>
                <th class="p-2">Leverantör</th>
                <th class="p-2">Datum</th>
                <th class="p-2">Faktura</th>
                <th class="p-2">Balar</th>
                <th class="p-2">Betald</th>
                <th class="p-2">Status</th>
                <th class="p-2"></th>
            </tr>
            </thead>
            <tbody>
            <?php
            $deliveries = $db->query("SELECT * FROM deliveries ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($deliveries as $d):
                $bales = $db->query("SELECT * FROM bales WHERE delivery_id={$d['id']}")->fetchAll(PDO::FETCH_ASSOC);
                $total = count($bales);
                $badUnreimbursed = count(array_filter($bales, fn($b) => $b['is_bad'] && !$b['is_reimbursed']));
                $bad = count(array_filter($bales, fn($b) => $b['is_bad']));
                $open = count(array_filter($bales, fn($b) => $b['status'] == 'open'));
                $closed = count(array_filter($bales, fn($b) => $b['status'] == 'closed'));
                $color = $badUnreimbursed > 0 ? 'border-red-400' : ($open > 0 ? 'border-blue-400' : ($closed == $total ? 'border-green-400' : 'border-gray-300'));
                ?>
                <tr class="border-t border-l-4 <?=$color?>">
                    <td class="p-2"><?=htmlspecialchars($d['supplier'])?></td>
                    <td class="p-2"><?=$d['delivery_date']?></td>
                    <td class="p-2"><input value="<?=htmlspecialchars($d['invoice_number'])?>" onchange="updateDelivery(<?=$d['id']?>,this.value,<?=$d['paid']?>)" class="border rounded p-1 w-24"></td>
                    <td class="p-2 text-center"><?=$d['num_bales']?></td>
                    <td class="p-2 text-center"><input type="checkbox" <?=$d['paid']?'checked':''?> onchange="updateDelivery(<?=$d['id']?>,'<?=$d['invoice_number']?>',this.checked?1:0)"></td>
                    <td class="p-2 text-center"><?=$total?> totalt / <?=$open?> öppna / <?=$bad?> felaktiga</td>
                    <td class="p-2"><a href="?delivery=<?=$d['id']?>" class="text-blue-600 hover:underline">Visa →</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?php
    // --- Om användaren klickar in på en leverans ---
    if (isset($_GET['delivery'])) {
        $deliveryId = intval($_GET['delivery']);
        $delivery = $db->query("SELECT * FROM deliveries WHERE id=$deliveryId")->fetch(PDO::FETCH_ASSOC);

        $bales = $db->query("
    SELECT * FROM bales 
    WHERE delivery_id=$deliveryId 
    ORDER BY 
        CASE WHEN open_date IS NULL THEN 1 ELSE 0 END, 
        date(open_date) DESC, 
        id DESC
")->fetchAll(PDO::FETCH_ASSOC);

        ?>
        <div class="bg-white p-4 rounded shadow">
            <a href="?" class="text-blue-600 hover:underline">&larr; Tillbaka till leveranser</a>
            <h2 class="text-xl font-semibold mt-2 mb-4">Balar för <?=htmlspecialchars($delivery['supplier'])?> (<?=$delivery['delivery_date']?>)</h2>

            <!-- Tabellvy -->
            <table class="hidden md:table min-w-full text-sm border">
                <thead class="bg-gray-100 text-gray-700 uppercase">
                <tr>
                    <th class="p-2">#</th>
                    <th class="p-2">Status</th>
                    <th class="p-2">Öppnad</th>
                    <th class="p-2">Stängd</th>
                    <th class="p-2">Dagar öppen</th>
                    <th class="p-2 text-center">Åtgärder</th>
                </tr>
                </thead>
                <tbody>
                <?php
                foreach ($bales as $b):
                    $days = '-';
                    if ($b['open_date']) {
                        $start = new DateTime($b['open_date']);
                        $end = $b['close_date'] ? new DateTime($b['close_date']) : new DateTime();
                        $days = $start->diff($end)->days . ' dag' . ($start->diff($end)->days != 1 ? 'ar' : '');
                    }
                    ?>
                    <tr class="border-t">
                        <td class="p-2"><?=$b['id']?></td>
                        <td class="p-2 space-x-1">
                            <?php
                            if ($b['status']) echo badge($b['status']=='open'?'Öppen':'Stängd', $b['status']=='open'?'bg-blue-100 text-blue-800':'bg-green-100 text-green-800');
                            if ($b['is_bad']) echo badge('Felaktig','bg-red-100 text-red-800');
                            if ($b['is_reimbursed']) echo badge('Ersatt','bg-yellow-100 text-yellow-800');
                            ?>
                        </td>
                        <td class="p-2 editable-date text-blue-700_ _underline cursor-pointer"
                            data-id="<?=$b['id']?>" data-field="open_date"
                            data-locked="<?=in_array($b['status'], ['open','closed'])?'0':'1'?>">
                            <?=$b['open_date']?:'-'?>
                        </td>
                        <td class="p-2 editable-date text-blue-700_ _underline cursor-pointer"
                            data-id="<?=$b['id']?>" data-field="close_date"
                            data-locked="<?=in_array($b['status'], ['open','closed'])?'0':'1'?>">
                            <?=$b['close_date']?:'-'?>
                        </td>
                        <td class="p-2 text-center"><?=$days?></td>
                        <td class="p-2 flex flex-wrap gap-1 justify-center">
                            <button onclick="setStatus(<?=$b['id']?>,'open')" class="px-2 py-1 border rounded text-xs bg-gray-200">Öppen</button>
                            <button onclick="setStatus(<?=$b['id']?>,'closed')" class="px-2 py-1 border rounded text-xs bg-gray-200">Stängd</button>
                            <button onclick="toggleFlag(<?=$b['id']?>,'is_bad',<?=!$b['is_bad']?1:0?>)" class="px-2 py-1 border rounded text-xs bg-gray-200">Felaktig</button>
                            <button onclick="toggleFlag(<?=$b['id']?>,'is_reimbursed',<?=!$b['is_reimbursed']?1:0?>)" class="px-2 py-1 border rounded text-xs bg-gray-200">Ersatt</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Kortvy för mobil -->
            <div class="block md:hidden space-y-3 mt-3">
                <?php
                foreach ($bales as $b):
                    $days = '-';
                    if ($b['open_date']) {
                        $start = new DateTime($b['open_date']);
                        $end = $b['close_date'] ? new DateTime($b['close_date']) : new DateTime();
                        $days = $start->diff($end)->days . ' dag' . ($start->diff($end)->days != 1 ? 'ar' : '');
                    }
                    $color = $b['is_bad'] ? 'border-red-400' : ($b['status']=='open'?'border-blue-400':($b['status']=='closed'?'border-green-400':'border-gray-300'));
                    ?>
                    <div class="border-l-4 <?=$color?> bg-white p-3 rounded shadow">
                        <p class="font-semibold">#<?=$b['id']?> — <?=$b['status']=='open'?'Öppen':($b['status']=='closed'?'Stängd':'–')?></p>
                        <p>📅 Öppnad: <span class="editable-date text-blue-700 underline cursor-pointer"
                                           data-id="<?=$b['id']?>" data-field="open_date"
                                           data-locked="<?=in_array($b['status'], ['open','closed'])?'0':'1'?>">
      <?=$b['open_date']?:'-'?></span></p>
                        <p>📅 Stängd: <span class="editable-date text-blue-700 underline cursor-pointer"
                                           data-id="<?=$b['id']?>" data-field="close_date"
                                           data-locked="<?=in_array($b['status'], ['open','closed'])?'0':'1'?>">
      <?=$b['close_date']?:'-'?></span></p>
                        <p>⏱️ Dagar öppen: <?=$days?></p>
                        <div class="flex flex-wrap gap-1 mt-2">
                            <button onclick="setStatus(<?=$b['id']?>,'open')" class="px-2 py-1 border rounded text-xs bg-gray-200 flex-1">Öppen</button>
                            <button onclick="setStatus(<?=$b['id']?>,'closed')" class="px-2 py-1 border rounded text-xs bg-gray-200 flex-1">Stängd</button>
                            <button onclick="toggleFlag(<?=$b['id']?>,'is_bad',<?=!$b['is_bad']?1:0?>)" class="px-2 py-1 border rounded text-xs bg-gray-200 flex-1">Felaktig</button>
                            <button onclick="toggleFlag(<?=$b['id']?>,'is_reimbursed',<?=!$b['is_reimbursed']?1:0?>)" class="px-2 py-1 border rounded text-xs bg-gray-200 flex-1">Ersatt</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <script>
            // --- Status- och flaggfunktioner ---
            async function setStatus(id,status){
                const fd=new FormData();fd.append('action','update_bale_status');fd.append('id',id);fd.append('status',status);
                const r=await fetch('',{method:'POST',body:fd});
                try{if((await r.json()).success)location.reload();}catch(e){alert('Fel vid uppdatering av status');}
            }

            async function toggleFlag(id,flag,val){
                const fd=new FormData();fd.append('action','toggle_flag');fd.append('id',id);fd.append('flag',flag);fd.append('value',val);
                try{const r=await fetch('',{method:'POST',body:fd});const j=await r.json();if(j.success)location.reload();}
                catch(e){alert('Fel vid uppdatering av flagga');}
            }

            // --- Redigerbara datumfält ---
            function makeDatesEditable(){
                document.querySelectorAll('.editable-date').forEach(el=>{
                    el.addEventListener('click',()=>{
                        if(el.dataset.locked==='1')return;
                        const current=el.textContent.trim();
                        const id=el.dataset.id;
                        const field=el.dataset.field;
                        const input=document.createElement('input');
                        input.type='date';
                        input.className='border rounded p-1 text-sm';
                        input.style.width='130px';
                        input.value=current!=='-'?current:'';
                        el.innerHTML='';el.appendChild(input);
                        input.focus();
                        input.showPicker && input.showPicker();
                        input.addEventListener('change',async()=>await saveDateChange(id,field,input.value));
                        input.addEventListener('blur',async()=>await saveDateChange(id,field,input.value));
                    });
                });
            }
            async function saveDateChange(id,field,value){
                const fd=new FormData();fd.append('action','update_date');fd.append('id',id);fd.append('field',field);fd.append('value',value);
                try{const r=await fetch('',{method:'POST',body:fd});const text=await r.text();const j=JSON.parse(text);
                    if(j.success)location.reload();
                }catch(e){alert('Fel vid uppdatering av datum');}
            }
            document.addEventListener('DOMContentLoaded',makeDatesEditable);
        </script>

        <?php
        exit;
    }
    ?>

    <script>
        // 🟢 Lägg till ny leverans (AJAX)
        document.getElementById('addDeliveryForm').addEventListener('submit', async e => {
            e.preventDefault();
            const fd = new FormData(e.target);
            fd.append('action', 'add_delivery');
            try {
                const r = await fetch('', { method: 'POST', body: fd });
                const text = await r.text();
                const j = JSON.parse(text);
                if (j.success) {
                    alert('✅ Leverans tillagd!');
                    location.reload();
                } else {
                    alert('Fel vid tillägg av leverans.');
                }
            } catch (err) {
                alert('Kunde inte ansluta till servern.');
                console.error(err);
            }
        });

        document.addEventListener('DOMContentLoaded', checkNotifications);

        async function checkNotifications() {
            const fd = new FormData();
            fd.append('action', 'check_notifications');
            const r = await fetch('', {method:'POST', body:fd});
            const j = await r.json();
            const existing = document.getElementById('notifications');
            if (existing) existing.remove();
            const div = document.createElement('div');
            div.id = 'notifications';
            div.className = "bg-yellow-100 border border-yellow-400 text-yellow-800 p-3 rounded mb-4";
            div.innerHTML = j.alerts.length > 0
                ? `<strong>⚠️ Varning!</strong> Följande balar har varit öppna längre än ${j.limitDays} dagar:
       <ul class='list-disc list-inside mt-2'>${j.alerts.map(b=>`<li>Bal #${b.id} (${b.supplier}) – öppnad ${b.open_date}</li>`).join('')}</ul>`
                : `<p class="text-sm text-gray-700">✅ Inga balar har varit öppna längre än ${j.limitDays} dagar.</p>`;
            document.querySelector('.max-w-6xl').insertBefore(div, document.querySelector('.max-w-6xl').firstChild);
        }

        async function updateDelivery(id, invoice, paid) {
            const fd = new FormData();
            fd.append('action','update_delivery');
            fd.append('id',id);
            fd.append('invoice',invoice);
            fd.append('paid',paid);
            await fetch('',{method:'POST',body:fd});
        }

        async function generateReport() {
            const fd=new FormData();fd.append('action','generate_full_report');
            const r=await fetch('',{method:'POST',body:fd});const j=await r.json();
            const modal=document.getElementById('reportModal');
            const content=document.getElementById('reportContent');
            modal.classList.remove('hidden');modal.style.opacity=0;setTimeout(()=>modal.style.opacity=1,50);
            if(!j.success){content.innerHTML='<p class="text-red-600">Fel vid skapande av rapport.</p>';return;}
            let html=`<p><strong>📊 Genomsnittlig tid öppen:</strong> ${j.avgDays} dagar</p>`;
            if(j.bad.length>0){html+=`<p class="mt-2 font-semibold">Felaktiga balar (ej ersatta):</p><ul class="list-disc list-inside">`;
                j.bad.forEach(b=>{html+=`<li>Bal #${b.bale_id} — Leveransdatum: ${b.delivery_date}</li>`});html+=`</ul>`;}
            else html+=`<p class="mt-2 text-gray-600">Inga felaktiga balar väntar på ersättning 🎉</p>`;
            html+=`<hr class="my-3"><h3 class="font-semibold text-lg mb-1">📈 Prognos</h3>

<ul class="list-disc list-inside">
  <li>Period: ${j.period} dagar</li>
    <li>Öppnade balar: ${j.openedCount}</li>
  <li>Förbrukningstakt: ${j.dailyRate} bal(ar)/dag</li>
  <li>Kvar i lager: ${j.remaining} balar</li>
  <li>Slut om: ${j.daysLeft??'–'} dagar</li>
  <li>Förväntat slutdatum: <strong>${j.forecastDate??'Ingen prognos'}</strong></li>
</ul>
  <button id="downloadCsvBtn" class="mt-3 bg-green-600 text-white px-3 py-1 rounded">⬇️ Ladda ner CSV</button>`;
            content.innerHTML=html;
            const btn=document.getElementById('downloadCsvBtn');
            if(btn)btn.addEventListener('click',()=>downloadCSV(j.bad));
        }

        function closeReport(){
            const m=document.getElementById('reportModal');
            m.style.opacity=0;setTimeout(()=>m.classList.add('hidden'),200);
        }
        document.addEventListener('click',e=>{
            const m=document.getElementById('reportModal');
            if(!m.classList.contains('hidden')&&e.target===m)closeReport();
        });
        function downloadCSV(data){
            if(!data.length)return;
            const headers=Object.keys(data[0]);
            const rows=data.map(r=>headers.map(h=>`"${r[h]||''}"`).join(','));
            const csv=[headers.join(','),...rows].join('\n');
            const blob=new Blob([csv],{type:'text/csv'});
            const url=URL.createObjectURL(blob);
            const a=document.createElement('a');a.href=url;a.download='rapport.csv';a.click();
            URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>

