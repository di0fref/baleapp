<?php
// haybale_cron.php — körs via cron och skickar varningsmejl via SMTP
error_reporting(E_ALL);
ini_set('display_errors',1);

require __DIR__ . '/vendor/autoload.php'; // ändra om du inte använder composer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// === Konfiguration ===
$db = new PDO('sqlite:' . __DIR__ . '/haybales.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$email_to = "fredrik.fahlstad@exsitec.se";   // mottagare
$email_from = "fredrik.fahlstad@gmail.com";  // din Gmail-adress (samma som du loggar in med)
$email_name = "Höbalsappen";
$email_subject = "Varning: Höbalar öppna för länge";
$email_logfile = __DIR__ . "/last_email_sent.txt";

// SMTP-inställningar
$smtp_host = "smtp.gmail.com";
$smtp_port = 587;
$smtp_user = "fredrik.fahlstad@gmail.com";   // din Gmail
$smtp_pass = "ztavwcnmyokfcnro"; // se nedan

// === Logik för att hitta balar ===
$month = (int)date('n');
$limit = ($month >= 5 && $month <= 8) ? 5 : 7;
$too_long = $db->query("
  SELECT b.id, d.supplier, b.open_date
  FROM bales b JOIN deliveries d ON d.id=b.delivery_id
  WHERE b.status='open' AND b.open_date <= date('now','-{$limit} day')
")->fetchAll(PDO::FETCH_ASSOC);

//if (!$too_long) exit;
//
$today = date('Y-m-d');
//if (file_exists($email_logfile) && trim(file_get_contents($email_logfile)) === $today) exit;

// === Skapa e-postmeddelande ===
$body = "Följande höbalar har varit öppna längre än {$limit} dagar:\n\n";
foreach ($too_long as $row) {
    $body .= "Bal #{$row['id']} (leverantör: {$row['supplier']}), öppnad {$row['open_date']}\n";
}
$body .= "\nÖppna Höbalsappen för att hantera dessa balar.\n\n— Höbalsappen";

// === Skicka via PHPMailer ===
$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host = $smtp_host;
    $mail->Port = $smtp_port;
    $mail->SMTPAuth = true;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Username = $smtp_user;
    $mail->Password = $smtp_pass;

    $mail->setFrom($email_from, $email_name);
    $mail->addAddress($email_to);
    $mail->Subject = $email_subject;
    $mail->Body = $body;
    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';
    $mail->Body = $body;
    $mail->send();
    echo "E-post skickad!\n";
    file_put_contents($email_logfile, $today);
} catch (Exception $e) {
    echo "E-post misslyckades: {$mail->ErrorInfo}\n";
}
