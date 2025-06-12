<?php
// File: controllers/core/mqtt_listener.php
// PHIÊN BẢN ĐỘC LẬP - ĐÃ SỬA LỖI ĐƯỜNG DẪN .ENV

// --- Bước 1: Nạp thư viện Composer ---
require __DIR__ . '/../../vendor/autoload.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

// --- Bước 2: Tự đọc file .env một cách đơn giản ---
// Sửa lại đường dẫn này cho đúng
$envPath = __DIR__ . '/../../.env'; 
if (!file_exists($envPath)) {
    die("File .env không được tìm thấy tại: $envPath");
}
$env = parse_ini_file($envPath);

// --- Bước 3: Đọc cấu hình và tự kết nối Database ---
$db_host = $env['DB_HOST'] ?? 'localhost';
$db_name = $env['DB_DATABASE'] ?? 'eclo-camera';
$db_user = $env['DB_USERNAME'] ?? 'root';
$db_pass = $env['DB_PASSWORD'] ?? '';
$db_charset = $env['DB_CHARSET'] ?? 'utf8mb4';

try {
    $dsn = "mysql:host=$db_host;dbname=$db_name;charset=$db_charset";
    $pdo = new PDO($dsn, $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Không thể kết nối đến database: " . $e->getMessage());
}
echo "Da ket noi Database thanh cong.\n";


// --- Bước 4: Đọc cấu hình MQTT từ .env và chạy listener ---
$server   = $env['MQTT_HOST'] ?? 'mqtt.eclo.io';
$port     = (int)($env['MQTT_PORT'] ?? 1883);
$clientId = 'eclo-listener-' . uniqid();
$username = $env['MQTT_USERNAME'] ?? 'eclo';
$password = $env['MQTT_PASSWORD'] ?? '';
$topic    = 'mqtt/face/1018656/Rec';

$mqtt = new MqttClient($server, $port, $clientId);
$connectionSettings = (new ConnectionSettings)
    ->setUsername($username)
    ->setPassword($password);

try {
    $mqtt->connect($connectionSettings, true);
    echo "Da ket noi MQTT Broker thanh cong. Dang lang nghe tren topic: $topic\n";

    $mqtt->subscribe($topic, function ($topic, $message) use ($pdo) {
        echo "Nhan duoc tin nhan: " . date('Y-m-d H:i:s') . "\n";
        
        $payload = json_decode($message, true);

        if (isset($payload['info'])) {
            $info = $payload['info'];

            $stmt = $pdo->prepare(
                "INSERT INTO mqtt_messages (person_name, person_id, similarity) VALUES (:name, :pid, :sim)"
            );
            
            $stmt->execute([
                ':name' => $info['persionName'],
                ':pid'  => $info['personId'],
                ':sim'  => (float)$info['similarity1']
            ]);

            echo "Da luu du lieu cua '{$info['persionName']}' vao database.\n";
        }
    }, 0);

    $mqtt->loop(true);

} catch (Exception $e) {
    die("Loi MQTT: " . $e->getMessage());
}