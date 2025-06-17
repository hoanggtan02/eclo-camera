<?php

require __DIR__ . '/../../vendor/autoload.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use Medoo\Medoo;
use Predis\Client as RedisClient;

// ===================================================================
// == HELPER FUNCTIONS (HÀM HỖ TRỢ) ==
// ===================================================================

function save_image_from_base64(?string $picBase64, string $uploadPath, string $prefix, string $uniqueId): ?string
{
    if (empty($picBase64)) return null;
    try {
        list(, $data) = explode(',', $picBase64);
        $imageData = base64_decode($data);
        if ($imageData === false) return null;
        $imageName = $prefix . $uniqueId . '_' . time() . '.jpg';
        $fullPath = $uploadPath . '/' . $imageName;
        file_put_contents($fullPath, $imageData);
        return 'uploads/faces/' . $imageName;
    } catch (Exception $e) {
        echo "⚠️  Lỗi khi xử lý ảnh: " . $e->getMessage() . "\n";
        return null;
    }
}

function publish_to_redis(?RedisClient $redisInstance, string $channel, array $data): void
{
    if ($redisInstance) {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        $redisInstance->publish($channel, $payload);
        echo "📢 [REDIS] Đã publish sự kiện tới channel '{$channel}'.\n";
    }
}

// ===================================================================
// == MAIN SCRIPT (KỊCH BẢN CHÍNH) ==
// ===================================================================

// --- Tải file .env, Kết nối DB, Redis, MQTT ---
$envPath = __DIR__ . '/../../.env';
if (!file_exists($envPath)) {
    die("Lỗi: File .env không được tìm thấy tại: $envPath");
}
$env = parse_ini_file($envPath);

try {
    $database = new Medoo([
        'database_type' => $env['DB_CONNECTION'] ?? 'mysql',
        'database_name' => $env['DB_DATABASE'] ?? 'eclo-camera',
        'server'        => $env['DB_HOST'] ?? 'localhost',
        'username'      => $env['DB_USERNAME'] ?? 'root',
        'password'      => $env['DB_PASSWORD'] ?? '',
        'charset'       => $env['DB_CHARSET'] ?? 'utf8mb4',
        'error'         => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "✅ Đã kết nối Database bằng Medoo thành công.\n";
} catch (PDOException $e) {
    die("Không thể kết nối đến database bằng Medoo: " . $e->getMessage());
}

$redis = null;
try {
    $redis = new RedisClient([
        'scheme' => 'tcp',
        'host'   => $env['REDIS_HOST'] ?? '127.0.0.1',
        'port'   => (int)($env['REDIS_PORT'] ?? 6379),
    ]);
    $redis->ping();
    echo "✅ Đã kết nối Redis thành công.\n";
} catch (Exception $e) {
    echo "⚠️ Không thể kết nối Redis: " . $e->getMessage() . ". Real-time sẽ không hoạt động.\n";
    $redis = null;
}

$server        = $env['MQTT_HOST'] ?? 'mqtt.ellm.io';
$port          = (int)($env['MQTT_PORT'] ?? 443);
$clientId      = 'eclo-listener-' . uniqid();
$username      = $env['MQTT_USERNAME'] ?? 'eclo';
$password      = $env['MQTT_PASSWORD'] ?? 'Eclo@123';
$baseTopicPath = 'mqtt/face/1018656';
$wildcardTopic = $baseTopicPath . '/+';
$imageUploadPath = __DIR__ . '/../../public/uploads/faces';

if (!is_dir($imageUploadPath)) {
    mkdir($imageUploadPath, 0777, true);
}

$mqtt = new MqttClient($server, $port, $clientId);
$connectionSettings = (new ConnectionSettings)->setUsername($username)->setPassword($password);

// --- Khởi tạo và kết nối MQTT Client ---
try {
    $mqtt->connect($connectionSettings, true);
    
    // =========================================================================
    // == PHIÊN BẢN SUBSCRIBE HOÀN CHỈNH (ĐÃ SỬA LỖI CÚ PHÁP)                ==
    // =========================================================================
    $mqtt->subscribe($wildcardTopic, function ($topic, $message) use ($database, $redis, $imageUploadPath, $baseTopicPath) {
        
        echo "📨 Nhận được tin nhắn trên topic [{$topic}]: " . date('Y-m-d H:i:s') . "\n";
        
        $payload = json_decode($message, true);
        if (!$payload || !isset($payload['info'])) {
            echo "❌ Dữ liệu không hợp lệ hoặc thiếu 'info'.\n";
            return;
        }
        $info = $payload['info'];

        // A. Xử lý cho topic "Rec"
        if ($topic === $baseTopicPath . '/Rec') {
            $imageRelativePath = save_image_from_base64($info['pic'] ?? null, $imageUploadPath, 'rec_', $info['personId']);

            $insertData = [
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
            ];
            
            $database->insert('mqtt_messages', $insertData);
            $lastId = $database->id();
            echo "💾 [Rec] Đã lưu dữ liệu vào database.\n";

            // Chuẩn bị dữ liệu cho Frontend (đã format sẵn)
            $dataForFrontend = [
                'id'          => $lastId,
                'person_name' => $info['persionName'],
                'image_path'  => $imageRelativePath ? '<img src="/public/' . $imageRelativePath . '" alt="Face" class="img-thumbnail" style="width: 60px; height: auto;">' : 'Không có ảnh',
                'event_time'  => date('H:i:s d/m/Y', strtotime($info['time'])),
                'is_no_mask'  => (int)$info['isNoMask'] ? '<span class="badge bg-danger">Không khẩu trang</span>' : '<span class="badge bg-success">Có khẩu trang</span>',
                'person_id'   => $info['personId'],
                'similarity'  => number_format($info['similarity1'], 2) . '%',
                'person_type' => ($info['PersonType'] == 0 ? 'Nhân viên' : 'Người lạ'),
            ];
            
            publish_to_redis($redis, 'rec-events', $dataForFrontend);
        }
        
        // B. Xử lý cho topic "Snap"
        elseif ($topic === $baseTopicPath . '/Snap') {
            $imageRelativePath = save_image_from_base64($info['pic'] ?? null, $imageUploadPath, 'snap_', $info['SnapID'] ?? uniqid());

            $insertData = [
                'event_type'    => 'Snap',
                'record_id'     => (int)($info['SnapID'] ?? 0),
                'is_no_mask'    => (int)($info['isNoMask'] ?? 0),
                'event_time'    => $info['time'],
                'image_path'    => $imageRelativePath,
            ];
            
            $database->insert('mqtt_messages', $insertData);
            $lastId = $database->id();
            echo "💾 [Snap] Đã lưu ảnh chụp nhanh vào database.\n";

            // Chuẩn bị dữ liệu cho Frontend (đã format sẵn)
            $dataForFrontend = [
                'id'          => $lastId,
                'person_name' => '<b>Người lạ</b>',
                'image_path'  => $imageRelativePath ? '<img src="/public/' . $imageRelativePath . '" alt="Face" class="img-thumbnail" style="width: 60px; height: auto;">' : 'Không có ảnh',
                'event_time'  => date('H:i:s d/m/Y', strtotime($info['time'])),
                'is_no_mask'  => (int)($info['isNoMask'] ?? 0) ? '<span class="badge bg-danger">Không khẩu trang</span>' : '<span class="badge bg-success">Có khẩu trang</span>',
                // Cung cấp các key rỗng để nhất quán cấu trúc
                'person_id'   => '',
                'similarity'  => '',
                'person_type' => 'Chụp nhanh',
            ];
            
            publish_to_redis($redis, 'snap-events', $dataForFrontend);
        }
    }, 0);

    echo "✅ Đã kết nối MQTT Broker thành công. Đang lắng nghe trên topic: $wildcardTopic\n";
    $mqtt->loop(true);

} catch (Exception $e) {
    die("❌ Lỗi MQTT: " . $e->getMessage());
}