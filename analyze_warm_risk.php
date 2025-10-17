<?php
// =========================================
// Höbalsapp - Warm Prediction Trainer (v1.0)
// =========================================

$db = new PDO('sqlite:' . __DIR__ . '/haybales.db');

// === SETTINGS ===
$lat = 58.4; // your farm latitude
$lon = 15.6; // your farm longitude

function getSmhiTempHistory($lat, $lon, $dateStart, $dateEnd) {
    // For simplicity, we’ll use the forecast API for recent data
    // Historical SMHI data requires meteorological datasets, but we can estimate
    $url = "https://opendata-download-metfcst.smhi.se/api/category/pmp3g/version/2/geotype/point/lon/$lon/lat/$lat/data.json";

    $json = @file_get_contents($url);
    if(!$json) return null;
    $data = json_decode($json, true);
    $temps = [];
    foreach($data['timeSeries'] as $ts){
        $time = substr($ts['validTime'], 0, 10);
        if($time >= $dateStart && $time <= $dateEnd){
            foreach($ts['parameters'] as $p){
                if($p['name'] === 't') $temps[] = $p['values'][0];
            }
        }
    }
    if(!$temps) return null;
    return array_sum($temps)/count($temps);
}

// === Fetch bales with warm data ===
$rows = $db->query("SELECT id, open_date, warm_date FROM bales WHERE open_date IS NOT NULL AND warm_date IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);

$data = [];
foreach($rows as $r){

    $open = new DateTime($r['open_date']);
    $warm = new DateTime($r['warm_date']);
    $days = $open->diff($warm)->days;
    if($days <= 0) continue;

    $avgTemp = getSmhiTempHistory($lat, $lon, $open->format('Y-m-d'), $warm->format('Y-m-d'));
    if($avgTemp === null) continue;

    $data[] = ['days' => $days, 'temp' => $avgTemp];
}

if(empty($data)){
    echo "⚠️ Inga datapunkter att analysera.\n";
    exit;
}

// === Linear regression ===
$n = count($data);
$sumX = array_sum(array_column($data, 'temp'));
$sumY = array_sum(array_column($data, 'days'));
$sumXY = 0; $sumX2 = 0;
foreach($data as $d){
    $sumXY += $d['temp'] * $d['days'];
    $sumX2 += pow($d['temp'], 2);
}
$b = ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - pow($sumX, 2));
$a = ($sumY - $b * $sumX) / $n;

// === Save model ===
$model = [
    'a' => round($a, 3),
    'b' => round($b, 3),
    'points' => $n,
    'updated' => date('Y-m-d H:i:s')
];
file_put_contents(__DIR__ . '/warm_model.json', json_encode($model, JSON_PRETTY_PRINT));

echo "✅ Modell tränad och sparad.\n";
echo "days_to_warm = {$model['a']} + {$model['b']} * temp\n";
echo "Datapunkter: {$n}\n";
