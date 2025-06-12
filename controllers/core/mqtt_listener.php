<?php
// File: controllers/core/mqtt_listener.php
// PHIÊN BẢN HOÀN CHỈNH - XỬ LÝ 2 TOPIC REC VÀ SNAP

require __DIR__ . '/../../vendor/autoload.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use Medoo\Medoo;

// --- Bước 1, 2, 3: Giữ nguyên (Nạp thư viện, đọc .env, kết nối DB) ---
$envPath = __DIR__ . '/../../.env';
if (!file_exists($envPath)) {
    die("Lỗi: File .env không được tìm thấy tại: $envPath");
}
$env = parse_ini_file($envPath);

try {
    $database = new Medoo([
        'database_type' => 'mysql',
        'database_name' => $env['DB_DATABASE'] ?? 'eclo-camera',
        'server'        => $env['DB_HOST'] ?? 'localhost',
        'username'      => $env['DB_USERNAME'] ?? 'root',
        'password'      => $env['DB_PASSWORD'] ?? '',
        'charset'       => $env['DB_CHARSET'] ?? 'utf8mb4',
        'error'         => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    die("Không thể kết nối đến database bằng Medoo: " . $e->getMessage());
}
echo "✅ Đã kết nối Database bằng Medoo thành công.\n";


// --- Bước 4: Cấu hình MQTT và chạy listener ---
$server   = $env['MQTT_HOST'] ?? 'mqtt.ellm.io';
$port     = (int)($env['MQTT_PORT'] ?? 1883);
$clientId = 'eclo-listener-' . uniqid();
$username = $env['MQTT_USERNAME'] ?? 'eclo';
$password = $env['MQTT_PASSWORD'] ?? 'Eclo@123';

$baseTopicPath = 'mqtt/face/1018656';
$wildcardTopic = $baseTopicPath . '/+';

$imageUploadPath = __DIR__ . '/../../public/uploads/faces';
if (!is_dir($imageUploadPath)) {
    mkdir($imageUploadPath, 0777, true);
}


$mqtt = new MqttClient($server, $port, $clientId);
$connectionSettings = (new ConnectionSettings)
    ->setUsername($username)
    ->setPassword($password);

try {
    $mqtt->connect($connectionSettings, true);
    
    $mqtt->subscribe($wildcardTopic, function ($topic, $message) use ($database, $imageUploadPath, $baseTopicPath) {
        echo "📨 Nhận được tin nhắn trên topic [{$topic}]: " . date('Y-m-d H:i:s') . "\n";
        
        $payload = json_decode($message, true);

        // A. Xử lý cho topic "Rec" (Nhận diện)
        if ($topic === $baseTopicPath . '/Rec') {
            if (isset($payload['info'])) {
                $info = $payload['info'];
                $imageRelativePath = null;

                if (!empty($info['pic'])) {
                    list($type, $data) = explode(';', $info['pic']);
                    list(, $data)      = explode(',', $data);
                    $imageData = base64_decode($data);
                    $imageName = $info['personId'] . '_' . time() . '_' . uniqid() . '.jpg';
                    $fullPath = $imageUploadPath . '/' . $imageName;
                    file_put_contents($fullPath, $imageData);
                    $imageRelativePath = 'uploads/faces/' . $imageName;
                    echo "🖼️  Đã lưu ảnh nhận diện: " . $imageRelativePath . "\n";
                }
                
                $database->insert('mqtt_messages', [
                    'event_type'    => 'Rec',
                    'person_name'   => $info['persionName'],
                    'person_id'     => $info['personId'],
                    'similarity'    => (float)$info['similarity1'],
                    'record_id'     => (int)$info['RecordID'],
                    'person_type'   => (int)$info['PersonType'],
                    'is_no_mask'    => (int)$info['isNoMask'],
                    'verify_status' => (int)$info['VerifyStatus'],
                    'event_time'    => $info['time'],
                    'image_path'    => $imageRelativePath,
                ]);

                echo "💾 [REC] Đã lưu dữ liệu của '{$info['persionName']}' vào database.\n";
            }
        }

        // B. Xử lý cho topic "Snap" (Đã cập nhật theo dữ liệu mới)
        elseif ($topic === $baseTopicPath . '/Snap') {
            if (isset($payload['info'])) {
                $info = $payload['info'];
                $imageRelativePath = null;

                // Xử lý và lưu ảnh từ 'pic'
                if (!empty($info['pic'])) {
                    list($type, $data) = explode(';', $info['pic']);
                    list(, $data)      = explode(',', $data);
                    $imageData = base64_decode($data);
                    
                    // Tạo tên file ảnh dựa trên SnapID để đảm bảo duy nhất
                    $imageName = 'snap_' . ($info['SnapID'] ?? time()) . '_' . uniqid() . '.jpg';
                    $fullPath = $imageUploadPath . '/' . $imageName;
                    file_put_contents($fullPath, $imageData);
                    $imageRelativePath = 'uploads/faces/' . $imageName;
                    echo "🖼️  Đã lưu ảnh chụp nhanh: " . $imageRelativePath . "\n";
                }

                // Tạo câu lệnh INSERT chỉ với các cột có trong dữ liệu Snap
                $database->insert('mqtt_messages', [
                    'event_type'    => 'Snap',
                    'record_id'     => (int)($info['SnapID'] ?? 0),
                    'is_no_mask'    => (int)($info['isNoMask'] ?? 0),
                    'event_time'    => $info['time'],
                    'image_path'    => $imageRelativePath,
                    // Các trường không có trong topic Snap sẽ tự động là NULL
                    // person_name, person_id, similarity, person_type, verify_status
                ]);

                echo "💾 [SNAP] Đã lưu ảnh chụp nhanh vào database.\n";
            }
        }

    }, 0);

    echo "✅ Đã kết nối MQTT Broker thành công. Đang lắng nghe trên topic: $wildcardTopic\n";
    $mqtt->loop(true);

} catch (Exception $e) {
    die("❌ Lỗi MQTT: " . $e->getMessage());
}