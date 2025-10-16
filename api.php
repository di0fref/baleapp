<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = new PDO('sqlite:' . __DIR__ . '/haybales.db');
$db->exec("PRAGMA foreign_keys = ON;");

/* === Hjälpfunktion för automatisk migrering === */
function ensureColumn($db, $table, $column, $definition) {
    $cols = array_column($db->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array($column, $cols)) {
        $db->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    }
}

/* === Skapa tabeller om de saknas === */
if (!$db->query("SELECT name FROM sqlite_master WHERE name='deliveries'")->fetch()) {
    $db->exec("CREATE TABLE deliveries(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        supplier TEXT,
        delivery_date TEXT,
        num_bales INTEGER,
        paid INTEGER DEFAULT 0,
        invoice_file TEXT,
        price REAL DEFAULT 0,
        weight REAL DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
}

if (!$db->query("SELECT name FROM sqlite_master WHERE name='bales'")->fetch()) {
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
        opened_by TEXT,
        closed_by TEXT,
        marked_bad_by TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(delivery_id) REFERENCES deliveries(id)
    )");
}

if (!$db->query("SELECT name FROM sqlite_master WHERE name='users'")->fetch()) {
    $db->exec("CREATE TABLE users(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE,
        password TEXT
    )");
}

/* === Automatiska kolumner === */
ensureColumn($db, 'deliveries', 'paid', 'INTEGER DEFAULT 0');
ensureColumn($db, 'deliveries', 'invoice_file', 'TEXT');
ensureColumn($db, 'deliveries', 'num_bales', 'INTEGER DEFAULT 0');
ensureColumn($db, 'deliveries', 'price', 'REAL DEFAULT 0');
ensureColumn($db, 'deliveries', 'weight', 'REAL DEFAULT 0');

ensureColumn($db, 'bales', 'status', 'TEXT');
ensureColumn($db, 'bales', 'is_bad', 'INTEGER DEFAULT 0');
ensureColumn($db, 'bales', 'is_reimbursed', 'INTEGER DEFAULT 0');
ensureColumn($db, 'bales', 'open_date', 'TEXT');
ensureColumn($db, 'bales', 'close_date', 'TEXT');
ensureColumn($db, 'bales', 'photo', 'TEXT');
ensureColumn($db, 'bales', 'opened_by', 'TEXT');
ensureColumn($db, 'bales', 'closed_by', 'TEXT');
ensureColumn($db, 'bales', 'marked_bad_by', 'TEXT');
ensureColumn($db, 'bales', 'reimbursed_date', 'TEXT');

/* === Standardanvändare === */
if (!$db->query("SELECT COUNT(*) FROM users")->fetchColumn()) {
    $db->exec("INSERT INTO users(username,password)VALUES('Erika','Erika')");
    $db->exec("INSERT INTO users(username,password)VALUES('Fredrik','Fredrik')");
}

/* === Login === */
if (isset($_POST['login_user'], $_POST['login_pass'])) {
    $stmt = $db->prepare("SELECT * FROM users WHERE username=? AND password=?");
    $stmt->execute([$_POST['login_user'], $_POST['login_pass']]);
    $usr = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($usr) {
        $_SESSION['user'] = $usr['username'];
        setcookie('hayuser', $usr['username'], time() + 86400 * 30, "/");
        header("Location: index.php");
        exit;
    } else {
        echo "<p>Fel användarnamn eller lösenord.</p>";
        exit;
    }
}

/* === API-actions === */
if (!isset($_POST['action'])) exit;

$a = $_POST['action'];
$user = $_SESSION['user'] ?? 'okänd';

/* === Lägg till leverans === */
if ($a === 'add_delivery') {
    $st = $db->prepare("INSERT INTO deliveries(supplier,delivery_date,num_bales)VALUES(?,?,?)");
    $st->execute([$_POST['supplier'], $_POST['date'], $_POST['bales']]);
    $did = $db->lastInsertId();
    $ins = $db->prepare("INSERT INTO bales(delivery_id)VALUES(?)");
    for ($i = 0; $i < intval($_POST['bales']); $i++) $ins->execute([$did]);
    echo json_encode(['success'=>true]); exit;
}

/* === Uppdatera fält (pris/vikt) === */
if ($a === 'update_delivery_field') {
    $id = (int)$_POST['id'];
    $field = $_POST['field'];
    $value = $_POST['value'];
    if (!in_array($field, ['price','weight'])) exit;
    $db->prepare("UPDATE deliveries SET $field=? WHERE id=?")->execute([$value, $id]);
    echo json_encode(['success'=>true]); exit;
}

/* === Kostnadsprognos === */
if ($a === 'predict_costs') {
    $avgPrice = $db->query("SELECT AVG(price / NULLIF(num_bales,0)) FROM deliveries WHERE num_bales>0")->fetchColumn() ?: 0;
    $opened = (int)$db->query("SELECT COUNT(*) FROM bales WHERE open_date>=date('now','-30 day')")->fetchColumn();
    $rate = $opened / 30;
    $remaining = (int)$db->query("SELECT COUNT(*) FROM bales WHERE open_date IS NULL")->fetchColumn();

    $forecast = [];
    $today = new DateTime();
    for ($m=1;$m<=6;$m++){
        $days = 30*$m;
        $used = $rate*$days;
        $cost = $used*$avgPrice;
        $month = (clone $today)->add(new DateInterval("P{$days}D"))->format('Y-m');
        $forecast[]=['month'=>$month,'bales_used'=>round($used),'estimated_cost'=>round($cost,2)];
    }

    echo json_encode([
        'success'=>true,
        'avg_price'=>round($avgPrice,2),
        'daily_rate'=>round($rate,2),
        'forecast'=>$forecast,
        'remaining'=>$remaining
    ]);
    exit;
}

/* === Uppdatera betald-status === */
if ($a === 'update_delivery') {
    $db->prepare("UPDATE deliveries SET paid=? WHERE id=?")->execute([$_POST['paid'], $_POST['id']]);
    echo json_encode(['success'=>true]); exit;
}

/* === Fakturauppladdning === */
if ($a === 'upload_invoice_file') {
    $fn = time().'_'.preg_replace('/[^a-zA-Z0-9_\.-]/','_',$_FILES['invoice_file']['name']);
    $dest = __DIR__."/uploads/invoices/".$fn;
    if (!is_dir(dirname($dest))) mkdir(dirname($dest),0775,true);
    move_uploaded_file($_FILES['invoice_file']['tmp_name'],$dest);
    $p = "uploads/invoices/".$fn;
    $db->prepare("UPDATE deliveries SET invoice_file=? WHERE id=?")->execute([$p,$_POST['id']]);
    echo json_encode(['success'=>true,'file'=>$p]); exit;
}

/* === Ta bort faktura === */
if ($a === 'delete_invoice_file') {
    $id = (int)$_POST['id'];
    $p = $db->query("SELECT invoice_file FROM deliveries WHERE id=$id")->fetchColumn();
    if ($p && file_exists(__DIR__.'/'.$p)) unlink(__DIR__.'/'.$p);
    $db->prepare("UPDATE deliveries SET invoice_file=NULL WHERE id=?")->execute([$id]);
    echo json_encode(['success'=>true]); exit;
}

/* === Uppdatera balstatus === */
if ($a === 'update_bale_status') {
    $id = (int)$_POST['id']; $s = $_POST['status'];
    if ($s==='open')
        $db->prepare("UPDATE bales SET status='open', open_date=COALESCE(open_date,date('now')), closed_by=NULL, close_date=NULL, opened_by=? WHERE id=?")->execute([$user,$id]);
    elseif ($s==='closed')
        $db->prepare("UPDATE bales SET status='closed', close_date=COALESCE(close_date,date('now')), closed_by=? WHERE id=?")->execute([$user,$id]);
    else
        $db->prepare("UPDATE bales SET status=NULL, open_date=NULL, close_date=NULL WHERE id=?")->execute([$id]);
    echo json_encode(['success'=>true]); exit;
}

/* === Markera flaggor (felaktig/ersatt) === */
if ($a==='toggle_flag') {
    $id=(int)$_POST['id']; $flag=$_POST['flag']; $val=(int)$_POST['value'];
    if($flag==='is_bad'){
        if($val)
            $db->prepare("UPDATE bales SET is_bad=1,marked_bad_by=?,status=NULL,open_date=NULL,close_date=NULL WHERE id=?")->execute([$user,$id]);
        else
            $db->prepare("UPDATE bales SET is_bad=0,marked_bad_by=NULL WHERE id=?")->execute([$id]);
    } elseif($flag==='is_reimbursed'){
        if($val)
            $db->prepare("UPDATE bales SET is_reimbursed=1,reimbursed_date=date('now') WHERE id=?")->execute([$id]);
        else
            $db->prepare("UPDATE bales SET is_reimbursed=0,reimbursed_date=NULL WHERE id=?")->execute([$id]);
    }
    echo json_encode(['success'=>true]); exit;
}

/* === Uppdatera datum === */
if ($a==='update_date'){
    $id=(int)$_POST['id']; $f=$_POST['field']; $v=$_POST['value']?:null;
    if(!in_array($f,['open_date','close_date'])) exit;
    $st=$db->query("SELECT status FROM bales WHERE id=$id")->fetchColumn();
    if(!in_array($st,['open','closed'])) { echo json_encode(['success'=>false]); exit; }
    $db->prepare("UPDATE bales SET $f=? WHERE id=?")->execute([$v,$id]);
    echo json_encode(['success'=>true]); exit;
}

/* === Ladda upp foto === */
if ($a==='upload_photo'){
    $id=(int)$_POST['id']; $f=$_FILES['photo'];
    if(!$f||$f['error']) {echo json_encode(['success'=>false]);exit;}
    $fn=time().'_'.preg_replace('/[^a-zA-Z0-9_\.-]/','_',$f['name']);
    $dest=__DIR__."/uploads/bale_photos/".$fn;
    if(!is_dir(dirname($dest))) mkdir(dirname($dest),0775,true);
    move_uploaded_file($f['tmp_name'],$dest);
    $p="uploads/bale_photos/".$fn;
    $db->prepare("UPDATE bales SET photo=? WHERE id=?")->execute([$p,$id]);
    echo json_encode(['success'=>true,'path'=>$p]); exit;
}

/* === Ta bort foto === */
if ($a==='delete_photo'){
    $id=(int)$_POST['id'];
    $photo=$db->query("SELECT photo FROM bales WHERE id=$id")->fetchColumn();
    if($photo && file_exists(__DIR__.'/'.$photo)) unlink(__DIR__.'/'.$photo);
    $db->prepare("UPDATE bales SET photo=NULL WHERE id=?")->execute([$id]);
    echo json_encode(['success'=>true]); exit;
}

/* === Notifieringar === */
if ($a==='check_notifications'){
    $m=(int)date('n');
    $limit=($m>=5&&$m<=8)?5:7;
    $alerts=[];
    $alerts['open_long']=$db->query("SELECT b.id,d.supplier,b.open_date FROM bales b JOIN deliveries d ON d.id=b.delivery_id WHERE b.status='open' AND b.open_date<=date('now','-{$limit} day')")->fetchAll(PDO::FETCH_ASSOC);
    $alerts['unpaid']=$db->query("SELECT * FROM deliveries WHERE paid=0 AND delivery_date<=date('now','-30 day')")->fetchAll(PDO::FETCH_ASSOC);
    $recent=$db->query("SELECT COUNT(*) FROM deliveries WHERE delivery_date>=date('now','-30 day')")->fetchColumn();
    $alerts['no_recent']=$recent?[]:[['msg'=>'Ingen leverans de senaste 30 dagarna']];
    $u=$db->query("SELECT COUNT(*) FROM bales WHERE open_date IS NULL")->fetchColumn();
    $alerts['low_stock']=$u<3?[['remaining'=>$u]]:[];
    echo json_encode(['success'=>true,'limitDays'=>$limit,'alerts'=>$alerts]); exit;
}

/* === Generera rapport === */
if ($a==='generate_full_report'){
    $avgQ=$db->query("SELECT open_date,close_date FROM bales WHERE open_date IS NOT NULL AND close_date IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    $totDays=0;$count=0;
    foreach($avgQ as $x){$totDays+=(new DateTime($x['open_date']))->diff(new DateTime($x['close_date']))->days;$count++;}
    $avg=$count?round($totDays/$count,1):0;
    $bad=$db->query("SELECT b.id AS bale_id,d.delivery_date,d.supplier FROM bales b JOIN deliveries d ON d.id=b.delivery_id WHERE b.is_bad=1 AND b.is_reimbursed=0 ORDER BY d.delivery_date DESC")->fetchAll(PDO::FETCH_ASSOC);
    $period=30;
    $opened=$db->query("SELECT open_date FROM bales WHERE open_date IS NOT NULL AND date(open_date)>=date('now','-{$period} day')")->fetchAll(PDO::FETCH_ASSOC);
    $openCount=count($opened);
    $rate=$openCount/max(1,$period);
    $remaining=(int)$db->query("SELECT COUNT(*) FROM bales WHERE open_date IS NULL")->fetchColumn();
    $daysLeft=$rate>0?round($remaining/$rate,1):null;
    $forecastDate=$daysLeft?(new DateTime())->add(new DateInterval('P'.ceil($daysLeft).'D'))->format('Y-m-d'):null;
    echo json_encode([
        'success'=>true,
        'avgDays'=>$avg,
        'bad'=>$bad,
        'period'=>$period,
        'openedCount'=>$openCount,
        'dailyRate'=>round($rate,2),
        'remaining'=>$remaining,
        'daysLeft'=>$daysLeft,
        'forecastDate'=>$forecastDate
    ]);
    exit;
}
if ($a === 'list_deliveries') {
    $deliveries = $db->query("SELECT * FROM deliveries ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($deliveries as &$d) {
        $stats = $db->query("SELECT status,is_bad,is_reimbursed FROM bales WHERE delivery_id={$d['id']}")->fetchAll(PDO::FETCH_ASSOC);
        $d['stats'] = [
            'total' => count($stats),
            'open' => count(array_filter($stats, fn($x)=>$x['status']=='open')),
            'bad' => count(array_filter($stats, fn($x)=>$x['is_bad'])),
            'unreimbursed' => count(array_filter($stats, fn($x)=>$x['is_bad']&&!$x['is_reimbursed']))
        ];
    }
    echo json_encode(['success'=>true,'deliveries'=>$deliveries]);
    exit;
}

if ($a === 'get_delivery') {
    $id = (int)$_POST['id'];
    $delivery = $db->query("SELECT * FROM deliveries WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    $bales = $db->query("SELECT * FROM bales WHERE delivery_id=$id ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success'=>true,'delivery'=>$delivery,'bales'=>$bales]);
    exit;
}

echo json_encode(['success'=>false,'msg'=>'Unknown']);
