<?php
define('ECLO', true);
require __DIR__ . '/path/to/your/framework/bootstrap.php'; // <-- THAY ĐỔI ĐƯỜNG DẪN NÀY

// ---- NẠP THƯ VIỆN VÀ CÁC LỚP CẦN THIẾT ----
require __DIR__ . '/vendor/autoload.php';

use \PhpMqtt\Client\MqttClient;
use \PhpMqtt\Client\ConnectionSettings;

// ---- LẤY CẤU HÌNH MQTT TỪ DATABASE/SETTINGS ----
// Sử dụng hàm của framework để lấy cấu hình đã lưu từ giao diện
$setting = $app->getValueData('setting');

$server   = $setting['mqtt_host'] ?? 'mqtt.eclo.io';      // Lấy từ setting
$port     = $setting['mqtt_port'] ?? 1883;               // Lấy từ setting
$clientId = $setting['mqtt_client_id'] ?? 'php_listener_' . uniqid();
$username = $setting['mqtt_username'] ?? null;           // Lấy từ setting
$password = $setting['mqtt_password'] ?? null;           // Lấy từ setting
$deviceId = '1018656'; 

echo "Starting MQTT Listener...\n";
echo "Connecting to {$server}:{$port}\n";

try {
    // ---- CẤU HÌNH KẾT NỐI ----
    $connectionSettings = (new ConnectionSettings)
        ->setUsername($username)
        ->setPassword($password)
        ->setKeepAliveInterval(60);

    $mqtt = new MqttClient($server, $port, $clientId);
    $mqtt->connect($connectionSettings, true);
    echo "Successfully connected to MQTT broker.\n";

    // ---- ĐĂNG KÝ TOPIC VÀ XỬ LÝ DỮ LIỆU ----
    
    // 1. Lắng nghe bản ghi nhận dạng (Rec)
    $topicRec = 'mqtt/face/' . $deviceId . '/Rec';
    $mqtt->subscribe($topicRec, function ($topic, $message) use ($app, $deviceId, $mqtt) {
        echo "Received message from topic: {$topic}\n";
        $data = json_decode($message, true);

        if (json_last_error() === JSON_ERROR_NONE && isset($data['info'])) {
            $info = $data['info'];
            echo "Processing RecordID: {$info['RecordID']}\n";
            
            // **LƯU VÀO DATABASE**
            // Giả sử bạn có một bảng `face_events` để lưu các sự kiện này
            $app->insert('face_events', [
                'record_id' => $info['RecordID'],
                'device_id' => $deviceId,
                'person_sn' => $info['PersonInfo']['PersonSN'] ?? null,
                'person_name' => $info['PersonInfo']['PersonName'] ?? 'Unknown',
                'event_time' => date('Y-m-d H:i:s', $info['Time']),
                'raw_data' => $message ,
            ]);
            
            echo "Saved recognition event for {$info['PersonInfo']['PersonName']} to database.\n";
            
            // Xử lý lưu ảnh (giữ nguyên logic của bạn)
            // ...
        } else {
            echo "JSON decode error or invalid data format.\n";
        }

    }, MqttClient::QOS_AT_MOST_ONCE);
    echo "Subscribed to: {$topicRec}\n";

    // 2. Lắng nghe bản ghi người lạ (Snap)
    $topicSnap = 'mqtt/face/' . $deviceId . '/Snap';
    $mqtt->subscribe($topicSnap, function ($topic, $message) use ($app, $deviceId) {
        echo "Received message from topic: {$topic}\n";
        $data = json_decode($message, true);

        if (json_last_error() === JSON_ERROR_NONE && isset($data['info'])) {
            $info = $data['info'];
            echo "Processing SnapID: {$info['SnapID']}\n";
            
            // **LƯU VÀO DATABASE**
            $app->insert('face_events', [
                'record_id' => $info['SnapID'],
                'device_id' => $deviceId,
                'person_name' => 'Stranger',
                'event_time' => date('Y-m-d H:i:s', $info['Time']),
                'type' => 'stranger', // 'stranger' cho người lạ
                'raw_data' => $message
            ]);

            echo "Saved stranger event to database.\n";
        }
    }, MqttClient::QOS_AT_MOST_ONCE);
    echo "Subscribed to: {$topicSnap}\n";
    
    // Các topic khác như 'heartbeat', 'basic' có thể được thêm vào tương tự

    // ---- BẮT ĐẦU VÒNG LẶP LẮNG NGHE ----
    $mqtt->loop(true);

} catch (\PhpMqtt\Client\Exceptions\MqttClientException $e) {
    echo "MQTT Error: " . $e->getMessage() . "\n";
} finally {
    if (isset($mqtt) && $mqtt->isConnected()) {
        $mqtt->disconnect();
    }
}
?>