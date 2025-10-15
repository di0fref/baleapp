<?php
// Höbalsapp v1.0 – 2025-10-15
session_start();
error_reporting(E_ALL); ini_set('display_errors', 1);

/* ====== Auth ====== */
$USERNAME = "admin";
$PASSWORD = "losenord";
if (!isset($_SESSION['user']) && isset($_COOKIE['hayuser'])) $_SESSION['user'] = $_COOKIE['hayuser'];
if (isset($_POST['login_user'], $_POST['login_pass'])) {
    if ($_POST['login_user'] === $USERNAME && $_POST['login_pass'] === $PASSWORD) {
        $_SESSION['user'] = $USERNAME;
        setcookie('hayuser', $USERNAME, time()+86400*30, "/");
        header("Location: ?"); exit;
    } else $login_error = "Fel användarnamn eller lösenord.";
}
if (isset($_GET['logout'])) { session_destroy(); setcookie('hayuser','',time()-3600,'/'); header("Location: ?"); exit; }
if (!isset($_SESSION['user'])): ?>
    <!DOCTYPE html><html lang="sv"><head>
        <meta charset="UTF-8"><title>Logga in - Höbalsapp</title>
        <script src="https://cdn.tailwindcss.com"></script><meta name="viewport" content="width=device-width, initial-scale=1">
    </head><body class="bg-gray-100 flex items-center justify-center h-screen">
    <div class="bg-white p-6 rounded shadow w-full max-w-sm">
        <h1 class="text-2xl font-bold mb-4 text-center">🌾 Höbalsapp</h1>
        <?php if(!empty($login_error)): ?><p class="text-red-600 text-center mb-2"><?= $login_error ?></p><?php endif; ?>
        <form method="POST" class="space-y-3">
            <input name="login_user" class="w-full border rounded p-2" placeholder="Användarnamn" required>
            <input type="password" name="login_pass" class="w-full border rounded p-2" placeholder="Lösenord" required>
            <button class="bg-green-600 text-white w-full rounded p-2">Logga in</button>
        </form>
    </div>
    </body></html>
    <?php exit; endif;

/* ====== DB (SQLite) ====== */
$db = new PDO('sqlite:' . __DIR__ . '/haybales.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE IF NOT EXISTS deliveries(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  supplier TEXT, delivery_date TEXT,
  num_bales INTEGER, paid INTEGER DEFAULT 0,
  invoice_file TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$db->exec("CREATE TABLE IF NOT EXISTS bales(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  delivery_id INTEGER,
  status TEXT,                -- NULL|'open'|'closed'
  is_bad INTEGER DEFAULT 0,   -- 0/1
  is_reimbursed INTEGER DEFAULT 0, -- 0/1
  open_date TEXT, close_date TEXT, reimbursed_date TEXT,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(delivery_id) REFERENCES deliveries(id)
)");
$migrated = false;
/* safe schema migration */
$colsD = array_column($db->query("PRAGMA table_info(deliveries)")->fetchAll(PDO::FETCH_ASSOC),'name');
foreach ([['paid',"ALTER TABLE deliveries ADD COLUMN paid INTEGER DEFAULT 0"],
             ['invoice_file',"ALTER TABLE deliveries ADD COLUMN invoice_file TEXT"],
             ['num_bales',"ALTER TABLE deliveries ADD COLUMN num_bales INTEGER DEFAULT 0"]] as $c) {
    if (!in_array($c[0], $colsD)) { $db->exec($c[1]); $migrated = true; }
}
$colsB = array_column($db->query("PRAGMA table_info(bales)")->fetchAll(PDO::FETCH_ASSOC),'name');
foreach ([['status',"ALTER TABLE bales ADD COLUMN status TEXT"],
             ['is_bad',"ALTER TABLE bales ADD COLUMN is_bad INTEGER DEFAULT 0"],
             ['is_reimbursed',"ALTER TABLE bales ADD COLUMN is_reimbursed INTEGER DEFAULT 0"],
             ['open_date',"ALTER TABLE bales ADD COLUMN open_date TEXT"],
             ['close_date',"ALTER TABLE bales ADD COLUMN close_date TEXT"],
             ['reimbursed_date',"ALTER TABLE bales ADD COLUMN reimbursed_date TEXT"]] as $c) {
    if (!in_array($c[0], $colsB)) { $db->exec($c[1]); $migrated = true; }
}

/* ====== AJAX API ====== */
if (isset($_POST['action'])) {
    $a = $_POST['action'];

    if ($a === 'add_delivery') {
        $stmt = $db->prepare("INSERT INTO deliveries(supplier,delivery_date,num_bales) VALUES (?,?,?)");
        $stmt->execute([$_POST['supplier'], $_POST['date'], $_POST['bales']]);
        $did = $db->lastInsertId();
        $ins = $db->prepare("INSERT INTO bales(delivery_id) VALUES (?)");
        for($i=0;$i<intval($_POST['bales']);$i++) $ins->execute([$did]);
        echo json_encode(['success'=>true]); exit;
    }

    if ($a === 'update_delivery') {
        $db->prepare("UPDATE deliveries SET paid=? WHERE id=?")->execute([$_POST['paid'], $_POST['id']]);
        echo json_encode(['success'=>true]); exit;
    }

    if ($a === 'upload_invoice_file') {
        $fileName = time().'_'.preg_replace('/[^a-zA-Z0-9_\.-]/','_',$_FILES['invoice_file']['name']);
        $dest = __DIR__."/uploads/invoices/".$fileName;
        if (!is_dir(dirname($dest))) mkdir(dirname($dest),0775,true);
        move_uploaded_file($_FILES['invoice_file']['tmp_name'], $dest);
        $path = "uploads/invoices/".$fileName;
        $db->prepare("UPDATE deliveries SET invoice_file=? WHERE id=?")->execute([$path, $_POST['id']]);
        echo json_encode(['success'=>true,'file'=>$path]); exit;
    }

    if ($a === 'delete_invoice_file') {
        $id = (int)$_POST['id'];
        $path = $db->query("SELECT invoice_file FROM deliveries WHERE id=$id")->fetchColumn();
        if ($path && file_exists(__DIR__.'/'.$path)) unlink(__DIR__.'/'.$path);
        $db->prepare("UPDATE deliveries SET invoice_file=NULL WHERE id=?")->execute([$id]);
        echo json_encode(['success'=>true]); exit;
    }

    if ($a === 'update_bale_status') {
        $id = (int)$_POST['id']; $s = $_POST['status'];
        if ($s==='open')   $db->prepare("UPDATE bales SET status='open', open_date=COALESCE(open_date, date('now')), close_date=NULL WHERE id=?")->execute([$id]);
        elseif ($s==='closed') $db->prepare("UPDATE bales SET status='closed', close_date=COALESCE(close_date, date('now')) WHERE id=?")->execute([$id]);
        else               $db->prepare("UPDATE bales SET status=NULL, open_date=NULL, close_date=NULL WHERE id=?")->execute([$id]);
        echo json_encode(['success'=>true]); exit;
    }

    if ($a === 'toggle_flag') {
        $id = (int)$_POST['id']; $flag=$_POST['flag']; $val = (int)$_POST['value'];
        if ($flag==='is_bad') {
            if ($val) $db->prepare("UPDATE bales SET is_bad=1, status=NULL, open_date=NULL, close_date=NULL WHERE id=?")->execute([$id]);
            else      $db->prepare("UPDATE bales SET is_bad=0 WHERE id=?")->execute([$id]);
        } elseif ($flag==='is_reimbursed') {
            if ($val) $db->prepare("UPDATE bales SET is_reimbursed=1, reimbursed_date=date('now') WHERE id=?")->execute([$id]);
            else      $db->prepare("UPDATE bales SET is_reimbursed=0, reimbursed_date=NULL WHERE id=?")->execute([$id]);
        }
        echo json_encode(['success'=>true]); exit;
    }

    if ($a === 'update_date') {
        $id=(int)$_POST['id']; $field=$_POST['field']; $val=$_POST['value']?:NULL;
        if (!in_array($field,['open_date','close_date'])) { echo json_encode(['success'=>false]); exit; }
        $st = $db->query("SELECT status FROM bales WHERE id=$id")->fetchColumn();
        if (!in_array($st,['open','closed'])) { echo json_encode(['success'=>false,'msg'=>'Ej tillåtet']); exit; }
        $stmt=$db->prepare("UPDATE bales SET $field=? WHERE id=?"); $stmt->execute([$val,$id]);
        echo json_encode(['success'=>true]); exit;
    }

    if ($a === 'check_notifications') {
        $m=(int)date('n'); $limit=($m>=5 && $m<=8)?5:7;
        $rows = $db->query("SELECT b.id,b.delivery_id,d.supplier,b.open_date
                        FROM bales b JOIN deliveries d ON d.id=b.delivery_id
                        WHERE b.status='open' AND b.open_date <= date('now','-{$limit} day')
                        ORDER BY b.open_date")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success'=>true,'limitDays'=>$limit,'alerts'=>$rows]); exit;
    }

    if ($a === 'generate_full_report') {
        $rows=$db->query("SELECT open_date,close_date FROM bales WHERE open_date IS NOT NULL AND close_date IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
        $total=0; $count=0; foreach($rows as $r){ $total += (new DateTime($r['open_date']))->diff(new DateTime($r['close_date']))->days; $count++; }
        $avgDays = $count ? round($total/$count,2) : 0;

        $bad = $db->query("SELECT b.id AS bale_id, d.delivery_date
                       FROM bales b JOIN deliveries d ON d.id=b.delivery_id
                       WHERE b.is_bad=1 AND b.is_reimbursed=0
                       ORDER BY d.delivery_date")->fetchAll(PDO::FETCH_ASSOC);

        $period=30;
        $opened=$db->query("SELECT open_date FROM bales WHERE open_date IS NOT NULL AND date(open_date)>=date('now','-{$period} day')")->fetchAll(PDO::FETCH_ASSOC);
        $openedCount=count($opened);
        $dailyRate=$openedCount/max(1,$period);
        $remaining=(int)$db->query("SELECT COUNT(*) FROM bales WHERE open_date IS NULL")->fetchColumn();
        if ($remaining===0) { $daysLeft=0; $forecastDate=NULL; }
        else {
            $daysLeft = $dailyRate>0 ? round($remaining/$dailyRate,1) : NULL;
            $daysInt  = $daysLeft ? ceil($daysLeft) : 0;
            $forecastDate = $daysInt ? (new DateTime())->add(new DateInterval("P{$daysInt}D"))->format('Y-m-d') : NULL;
        }
        echo json_encode([
            'success'=>true,'avgDays'=>$avgDays,'bad'=>$bad,
            'period'=>$period,'openedCount'=>$openedCount,'dailyRate'=>round($dailyRate,2),
            'remaining'=>$remaining,'daysLeft'=>$daysLeft,'forecastDate'=>$forecastDate
        ]); exit;
    }

    // Unknown action
    echo json_encode(['success'=>false,'msg'=>'Unknown action']); exit;
}

/* ====== Page ====== */
$deliveryId = isset($_GET['delivery']) ? (int)$_GET['delivery'] : null;
?>
<!DOCTYPE html><html lang="sv"><head>
    <meta charset="UTF-8"><title>Höbalsapp</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>th,td{vertical-align:middle}</style>
</head><body class="bg-gray-50 text-gray-900">
<div class="max-w-6xl mx-auto p-4">
    <div class="flex justify-between items-center mb-4">
        <h1 class="text-2xl font-bold">🌾 Höbalsapp</h1>
        <div class="flex items-center gap-2">
            <span class="text-sm text-gray-600">👤 <?= htmlspecialchars($_SESSION['user']) ?></span>
            <a href="?logout" class="bg-red-600 hover:bg-red-700 text-white text-sm px-3 py-1 rounded">Logga ut</a>
        </div>
    </div>

    <!-- Report Modal -->
    <div id="reportModal" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-5 relative">
            <button onclick="closeReport()" class="absolute top-2 right-2 text-gray-500 hover:text-gray-700 text-xl font-bold">×</button>
            <h2 class="text-xl font-semibold mb-3">📊 Rapport</h2>
            <div id="reportContent" class="text-sm text-gray-800 space-y-2"><p>Laddar...</p></div>
            <div class="mt-4 text-right"><button onclick="closeReport()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-1 rounded">Stäng</button></div>
        </div>
    </div>

    <?php if (!$deliveryId): ?>
        <!-- Notifications -->
        <div id="notificationsMount"></div>

        <!-- Deliveries -->
        <div class="bg-white p-4 rounded shadow mb-6">
            <div class="flex justify-between items-center">
                <h2 class="text-xl font-semibold mb-2">Leveranser</h2>
                <button onclick="generateReport()" class="bg-indigo-600 text-white px-4 py-2 rounded">📊 Skapa rapport</button>
            </div>

            <form id="addDeliveryForm" class="grid grid-cols-1 md:grid-cols-4 gap-2 mb-4">
                <input name="supplier" class="border rounded p-2" placeholder="Leverantör" required>
                <input type="date" name="date" class="border rounded p-2" required>
                <input type="number" name="bales" class="border rounded p-2" placeholder="Antal balar" min="1" required>
                <button class="bg-green-600 text-white rounded p-2">Lägg till</button>
            </form>

            <table class="min-w-full text-sm border">
                <thead class="bg-gray-100 text-gray-700 uppercase">
                <tr>
                    <th class="p-2">Leverantör</th>
                    <th class="p-2">Datum</th>
                    <th class="p-2 text-center">Antal</th>
                    <th class="p-2 text-center">Status</th>
                    <th class="p-2 text-center">Betald</th>
                    <th class="p-2 text-center">Faktura</th>
                    <th class="p-2"></th>
                </tr>
                </thead>
                <tbody>
                <?php
                foreach($db->query("SELECT * FROM deliveries ORDER BY id DESC") as $d):
                    $rows = $db->query("SELECT status,is_bad,is_reimbursed FROM bales WHERE delivery_id={$d['id']}")->fetchAll(PDO::FETCH_ASSOC);
                    $tot = count($rows);
                    $open = count(array_filter($rows, fn($x)=>$x['status']=='open'));
                    $bad  = count(array_filter($rows, fn($x)=>$x['is_bad']));
                    $badUnr = count(array_filter($rows, fn($x)=>$x['is_bad'] && !$x['is_reimbursed']));
                    $border = $badUnr ? 'border-red-400' : 'border-gray-200';
                    ?>
                    <tr class="border-t border-l-4 <?= $border ?>">
                        <td class="p-2"><?= htmlspecialchars($d['supplier']) ?></td>
                        <td class="p-2"><?= $d['delivery_date'] ?></td>
                        <td class="p-2 text-center"><?= $tot ?></td>
                        <td class="p-2 text-center"><?= $open ?> öppna / <?= $bad ?> felaktiga</td>
                        <td class="p-2 text-center"><input type="checkbox" <?= $d['paid']?'checked':'' ?> onchange="updateDelivery(<?= $d['id'] ?>, this.checked?1:0)"></td>
                        <td class="p-2 text-center">
                            <?php if ($d['invoice_file']): ?>
                                <div class="flex flex-col items-center gap-1">
                                    <a href="<?= $d['invoice_file'] ?>" target="_blank" class="text-blue-600 underline">Visa PDF</a>
                                    <button onclick="deleteInvoice(<?= $d['id'] ?>)" class="text-xs text-red-600 hover:text-red-800">🗑️ Ta bort</button>
                                </div>
                            <?php else: ?>
                                <button onclick="uploadInvoice(<?= $d['id'] ?>)" class="text-sm bg-gray-200 hover:bg-gray-300 rounded px-2 py-1">📎 Ladda upp</button>
                            <?php endif; ?>
                        </td>
                        <td class="p-2 text-center"><a href="?delivery=<?= $d['id'] ?>" class="text-blue-600 hover:underline">Visa →</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php else:
        $delivery = $db->query("SELECT * FROM deliveries WHERE id=$deliveryId")->fetch(PDO::FETCH_ASSOC);
        $bales = $db->query("SELECT * FROM bales WHERE delivery_id=$deliveryId
                       ORDER BY CASE WHEN open_date IS NULL THEN 1 ELSE 0 END, date(open_date) ASC, id ASC")
            ->fetchAll(PDO::FETCH_ASSOC);
        $tot=count($bales); $open=count(array_filter($bales,fn($x)=>$x['status']=='open'));
        $closed=count(array_filter($bales,fn($x)=>$x['status']=='closed'));
        $bad=count(array_filter($bales,fn($x)=>$x['is_bad']));
        ?>
        <a href="?" class="text-blue-600 hover:underline">&larr; Tillbaka</a>
        <h2 class="text-xl font-semibold mb-3 mt-2">Balar för <?= htmlspecialchars($delivery['supplier']) ?> (<?= $delivery['delivery_date'] ?>)</h2>
        <p class="mb-2 text-sm text-gray-700">Totalt: <?= $tot ?> • Öppna: <?= $open ?> • Stängda: <?= $closed ?> • Felaktiga: <?= $bad ?></p>

        <table class="hidden md:table min-w-full text-sm border">
            <thead class="bg-gray-100 text-gray-700 uppercase">
            <tr><th class="p-2">#</th><th class="p-2">Status</th><th class="p-2">Öppnad</th><th class="p-2">Stängd</th><th class="p-2 text-center">Dagar</th><th class="p-2 text-center">Åtgärder</th></tr>
            </thead>
            <tbody>
            <?php foreach($bales as $b):
                $days='-'; if ($b['open_date']) { $d1=new DateTime($b['open_date']); $d2=$b['close_date']?new DateTime($b['close_date']):new DateTime(); $days=$d1->diff($d2)->days.' dagar'; }
                ?>
                <tr class="border-t">
                    <td class="p-2"><?= $b['id'] ?></td>
                    <td class="p-2">
                        <?php
                        if ($b['status']) echo "<span class='px-2 py-1 text-xs rounded ".($b['status']=='open'?'bg-blue-100 text-blue-800':'bg-green-100 text-green-800')."'>".($b['status']=='open'?'Öppen':'Stängd')."</span> ";
                        if ($b['is_bad']) echo "<span class='px-2 py-1 text-xs rounded bg-red-100 text-red-800'>Felaktig</span> ";
                        if ($b['is_reimbursed']) {
                            echo "<span class='px-2 py-1 text-xs rounded bg-yellow-100 text-yellow-800'>Ersatt</span> ";
                            if (!empty($delivery['invoice_file'])) echo "<a href='{$delivery['invoice_file']}' target='_blank' class='text-blue-600 underline text-xs'>Visa faktura</a>";
                        }
                        ?>
                    </td>
                    <td class="p-2 editable-date text-blue-700 underline cursor-pointer" data-id="<?= $b['id'] ?>" data-field="open_date" data-locked="<?= in_array($b['status'],['open','closed'])?'0':'1' ?>"><?= $b['open_date'] ?: '-' ?></td>
                    <td class="p-2 editable-date text-blue-700 underline cursor-pointer" data-id="<?= $b['id'] ?>" data-field="close_date" data-locked="<?= in_array($b['status'],['open','closed'])?'0':'1' ?>"><?= $b['close_date'] ?: '-' ?></td>
                    <td class="p-2 text-center"><?= $days ?></td>
                    <td class="p-2 flex flex-wrap gap-1 justify-center">
                        <button onclick="setStatus(<?= $b['id'] ?>,'open')" class="px-2 py-1 border rounded text-xs bg-gray-200">Öppen</button>
                        <button onclick="setStatus(<?= $b['id'] ?>,'closed')" class="px-2 py-1 border rounded text-xs bg-gray-200">Stängd</button>
                        <button onclick="toggleFlag(<?= $b['id'] ?>,'is_bad',<?= !$b['is_bad']?1:0 ?>)" class="px-2 py-1 border rounded text-xs bg-gray-200">Felaktig</button>
                        <button onclick="toggleFlag(<?= $b['id'] ?>,'is_reimbursed',<?= !$b['is_reimbursed']?1:0 ?>)" class="px-2 py-1 border rounded text-xs bg-gray-200">Ersatt</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Mobile cards -->
        <div class="block md:hidden space-y-3 mt-3">
            <?php foreach($bales as $b):
                $days='-'; if ($b['open_date']) { $d1=new DateTime($b['open_date']); $d2=$b['close_date']?new DateTime($b['close_date']):new DateTime(); $days=$d1->diff($d2)->days.' dagar'; }
                $color = $b['is_bad'] ? 'border-red-400' : ($b['status']=='open'?'border-blue-400':($b['status']=='closed'?'border-green-400':'border-gray-300'));
                ?>
                <div class="border-l-4 <?= $color ?> bg-white p-3 rounded shadow">
                    <p class="font-semibold">#<?= $b['id'] ?> — <?= $b['status']=='open'?'Öppen':($b['status']=='closed'?'Stängd':'–') ?></p>
                    <p>📅 Öppnad: <span class="editable-date text-blue-700 underline cursor-pointer" data-id="<?= $b['id'] ?>" data-field="open_date" data-locked="<?= in_array($b['status'],['open','closed'])?'0':'1' ?>"><?= $b['open_date'] ?: '-' ?></span></p>
                    <p>📅 Stängd: <span class="editable-date text-blue-700 underline cursor-pointer" data-id="<?= $b['id'] ?>" data-field="close_date" data-locked="<?= in_array($b['status'],['open','closed'])?'0':'1' ?>"><?= $b['close_date'] ?: '-' ?></span></p>
                    <p>⏱️ Dagar öppen: <?= $days ?></p>
                    <div class="flex flex-wrap gap-1 mt-2">
                        <button onclick="setStatus(<?= $b['id'] ?>,'open')" class="px-2 py-1 border rounded text-xs bg-gray-200 flex-1">Öppen</button>
                        <button onclick="setStatus(<?= $b['id'] ?>,'closed')" class="px-2 py-1 border rounded text-xs bg-gray-200 flex-1">Stängd</button>
                        <button onclick="toggleFlag(<?= $b['id'] ?>,'is_bad',<?= !$b['is_bad']?1:0 ?>)" class="px-2 py-1 border rounded text-xs bg-gray-200 flex-1">Felaktig</button>
                        <button onclick="toggleFlag(<?= $b['id'] ?>,'is_reimbursed',<?= !$b['is_reimbursed']?1:0 ?>)" class="px-2 py-1 border rounded text-xs bg-gray-200 flex-1">Ersatt</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Toasts -->
<div id="toastContainer" class="fixed bottom-4 right-4 flex flex-col gap-2 z-50 pointer-events-none"></div>

<script>
    function showToast(msg,type="success",duration=3000){
        const c=document.getElementById('toastContainer'); const t=document.createElement('div');
        const colors={success:'bg-green-600',error:'bg-red-600',info:'bg-blue-600'};
        t.className=(colors[type]||colors.success)+" text-white px-4 py-2 rounded shadow-md opacity-0 transition-opacity";
        t.textContent=msg; c.appendChild(t); setTimeout(()=>t.style.opacity=1,30);
        setTimeout(()=>{t.style.opacity=0; setTimeout(()=>t.remove(),300)},duration);
    }
    <?php if ($migrated): ?>showToast("✅ Databasen uppdaterades","info",2500);<?php endif; ?>

    /* Submit add-delivery */
    document.getElementById('addDeliveryForm')?.addEventListener('submit',async e=>{
        e.preventDefault();
        const fd=new FormData(e.target); fd.append('action','add_delivery');
        try{const r=await fetch('',{method:'POST',body:fd}); const j=await r.json();
            if(j.success){showToast('✅ Leverans tillagd!'); location.reload();}
            else alert('Fel vid tillägg.');
        }catch(e){alert('Kunde inte ansluta.');}
    });

    /* Delivery updates */
    async function updateDelivery(id,paid){
        const fd=new FormData(); fd.append('action','update_delivery'); fd.append('id',id); fd.append('paid',paid);
        await fetch('',{method:'POST',body:fd}); showToast('💾 Sparat');
    }
    async function uploadInvoice(id){
        const input=document.createElement('input'); input.type='file'; input.accept='application/pdf';
        input.onchange=async()=>{const f=input.files[0]; if(!f) return; if(f.size>5_000_000){alert('Filen är för stor');return;}
            const fd=new FormData(); fd.append('action','upload_invoice_file'); fd.append('id',id); fd.append('invoice_file',f);
            try{const r=await fetch('',{method:'POST',body:fd}); const j=await r.json();
                if(j.success){showToast('✅ Faktura uppladdad!'); location.reload();} else alert('Fel: '+(j.msg||''));}
            catch(e){alert('Kunde inte ladda upp.');}
        };
        input.click();
    }
    async function deleteInvoice(id){
        if(!confirm('Vill du ta bort denna faktura?')) return;
        const fd=new FormData(); fd.append('action','delete_invoice_file'); fd.append('id',id);
        const r=await fetch('',{method:'POST',body:fd}); const j=await r.json();
        if(j.success){showToast('🗑️ Faktura borttagen.'); location.reload();} else alert('Fel vid borttagning.');
    }

    /* Bale actions */
    async function setStatus(id,status){
        const fd=new FormData(); fd.append('action','update_bale_status'); fd.append('id',id); fd.append('status',status);
        try{const r=await fetch('',{method:'POST',body:fd}); await r.text(); showToast('✔️ Status uppdaterad'); location.reload();}
        catch(e){alert('Fel vid uppdatering.');}
    }
    async function toggleFlag(id,flag,val){
        const fd=new FormData(); fd.append('action','toggle_flag'); fd.append('id',id); fd.append('flag',flag); fd.append('value',val);
        try{const r=await fetch('',{method:'POST',body:fd}); await r.text(); showToast('✔️ Uppdaterad'); location.reload();}
        catch(e){alert('Fel vid uppdatering.');}
    }

    /* Editable dates */
    function makeDatesEditable(){
        document.querySelectorAll('.editable-date').forEach(el=>{
            el.addEventListener('click',()=>{
                if(el.dataset.locked==='1') return;
                const current=el.textContent.trim(); const id=el.dataset.id; const field=el.dataset.field;
                const input=document.createElement('input'); input.type='date'; input.className='border rounded p-1 text-sm'; input.value=current!=='-'?current:'';
                el.innerHTML=''; el.appendChild(input); input.focus();
                const save=async()=>{
                    const fd=new FormData(); fd.append('action','update_date'); fd.append('id',id); fd.append('field',field); fd.append('value',input.value);
                    try{const r=await fetch('',{method:'POST',body:fd}); const t=await r.text(); let j;
                        try{j=JSON.parse(t);}catch(_){alert('Serverfel.'); return;}
                        if(j.success){showToast('📅 Datum sparat'); location.reload();} else alert('Kunde inte spara datum.');
                    }catch(e){alert('Fel vid sparning.');}
                };
                input.addEventListener('change',save); input.addEventListener('blur',save);
            });
        });
    }
    document.addEventListener('DOMContentLoaded', makeDatesEditable);

    /* Report + forecast */
    async function generateReport(){
        const fd=new FormData(); fd.append('action','generate_full_report');
        try{
            const r=await fetch('',{method:'POST',body:fd}); const j=await r.json();
            const m=document.getElementById('reportModal'); const c=document.getElementById('reportContent');
            m.classList.remove('hidden');
            let html=`<p><strong>📊 Genomsnittlig tid öppen:</strong> ${j.avgDays} dagar</p>`;
            if(j.bad.length){
                html+=`<p class="mt-2 font-semibold">Felaktiga balar (ej ersatta):</p><ul class="list-disc list-inside">`;
                j.bad.forEach(b=>html+=`<li>Bal #${b.bale_id} — Leveransdatum: ${b.delivery_date}</li>`);
                html+=`</ul>`;
            } else html+=`<p class="mt-2 text-gray-600">Inga felaktiga balar väntar på ersättning 🎉</p>`;
            html+=`<hr class="my-3"><h3 class="font-semibold text-lg mb-1">📈 Prognos</h3>`;
            if(j.remaining===0){ html+=`<p>🎉 Alla balar är förbrukade – inget kvar i lager.</p>`; }
            else {
                html+=`<p>📅 Period: ${j.period} dagar</p>
             <p>📦 Öppnade balar: ${j.openedCount}</p>
             <p>⚡ Förbrukningstakt: ${j.dailyRate} bal(ar)/dag</p>
             <p>🪣 Kvar i lager: ${j.remaining} balar</p>
             <p>⏳ Slut om: ${j.daysLeft??'–'} dagar</p>
             <p>📉 Förväntat slutdatum: <strong>${j.forecastDate??'Ingen prognos'}</strong></p>`;
            }
            html+=`<button id="downloadCsvBtn" class="mt-3 bg-green-600 text-white px-3 py-1 rounded">⬇️ Ladda ner CSV</button>`;
            c.innerHTML=html; document.getElementById('downloadCsvBtn').onclick=()=>downloadCSV(j.bad);
            showToast('📊 Rapport skapad!','info',2000);
        }catch(e){ alert('Fel vid skapande av rapport.'); }
    }
    function closeReport(){ document.getElementById('reportModal').classList.add('hidden'); }
    function downloadCSV(data){
        if(!data||!data.length) return;
        const keys=Object.keys(data[0]);
        const rows=data.map(r=>keys.map(k=>`"${(r[k]??'').toString().replace(/"/g,'""')}"`).join(','));
        const csv=[keys.join(','),...rows].join('\n');
        const blob=new Blob([csv],{type:'text/csv'}); const url=URL.createObjectURL(blob);
        const a=document.createElement('a'); a.href=url; a.download='rapport.csv'; a.click(); URL.revokeObjectURL(url);
    }

    /* Notifications (5 days in summer, 7 otherwise) */
    async function checkNotifications(){
        const fd=new FormData(); fd.append('action','check_notifications');
        try{
            const r=await fetch('',{method:'POST',body:fd}); const j=await r.json();
            const mount=document.getElementById('notificationsMount'); if(!mount) return;
            mount.innerHTML=''; const d=document.createElement('div');
            d.className='bg-yellow-100 border border-yellow-400 text-yellow-800 p-3 rounded mb-4';
            if(j.alerts.length){
                let h=`<strong>⚠️ Varning!</strong> Balar öppna längre än ${j.limitDays} dagar:<ul class="list-disc list-inside mt-2">`;
                j.alerts.forEach(b=>h+=`<li>Bal #${b.id} (${b.supplier}) — öppnad ${b.open_date}</li>`); h+='</ul>'; d.innerHTML=h;
            } else d.innerHTML=`<p class="text-sm text-gray-700">✅ Inga balar har varit öppna längre än ${j.limitDays} dagar.</p>`;
            mount.appendChild(d);
        }catch(e){}
    }
    document.addEventListener('DOMContentLoaded', checkNotifications);
    setInterval(checkNotifications, 600000);
</script>
</body></html>
