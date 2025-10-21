<?php
$db = new PDO('sqlite:' . __DIR__ . '/haybales.db');

$modelFile = __DIR__ . '/warm_model.json';
$rows = $db->query("SELECT julianday(warm_date) - julianday(open_date) AS days, temp
                    FROM bales WHERE warm_date IS NOT NULL AND temp IS NOT NULL")
    ->fetchAll(PDO::FETCH_ASSOC);

if (count($rows) < 3) exit("Not enough data to train\n");

// Simple linear regression y = a + b*x
$meanX = array_sum(array_column($rows, 'temp')) / count($rows);
$meanY = array_sum(array_column($rows, 'days')) / count($rows);

$num = $den = 0;
foreach ($rows as $r) {
    $num += ($r['temp'] - $meanX) * ($r['days'] - $meanY);
    $den += pow($r['temp'] - $meanX, 2);
}
$b = $den ? $num / $den : 0;
$a = $meanY - $b * $meanX;

$model = ['a'=>$a, 'b'=>$b, 'trained'=>date('Y-m-d H:i:s')];
file_put_contents($modelFile, json_encode($model));

echo "Model updated: y = $a + $b*x\n";
