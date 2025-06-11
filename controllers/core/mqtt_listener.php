<?php
require 'vendor/autoload.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

// --- Các thông tin kết nối ---
$server   = 'mqtt.ellm.io';
$port     = 1883;
$username = 'eclo';
$password = 'Eclo@123';
$topic    = 'mqtt/face/1018656/Rec';

// --- THAY ĐỔI QUAN TRỌNG: Tạo Client ID ngẫu nhiên để tránh xung đột ---
$clientId = 'php-mqtt-client-' . uniqid();

// --- Bắt đầu kết nối ---
$mqtt = new MqttClient($server, $port, $clientId);

$connectionSettings = (new ConnectionSettings)
    ->setUsername($username)
    ->setPassword($password)
    ->setKeepAliveInterval(60);

try {
    $mqtt->connect($connectionSettings, true);
    echo "Connected to broker with Client ID: $clientId at " . date('Y-m-d H:i:s') . "!\n";

    $mqtt->subscribe($topic, function ($topic, $message) {
        echo sprintf(
            "Received message on topic [%s]: %s at %s\n",
            $topic,
            $message,
            date('Y-m-d H:i:s')
        );
    }, MqttClient::QOS_AT_LEAST_ONCE);
    
    echo "Subscribed to topic [$topic]. Waiting for messages...\n";

    // Chạy trong 5 phút (300 giây) để có đủ thời gian nhận dữ liệu
    $startTime = time();
    while ($mqtt->isConnected() && (time() - $startTime) < 300) { 
        $mqtt->loop();
        // Không cần echo liên tục trong vòng lặp để tránh rối console
        usleep(100000); // Đợi 0.1 giây
    }

    echo "Stopped listening after 5 minutes at " . date('Y-m-d H:i:s') . "\n";

} catch (Exception $e) {
    echo "Connection failed: " . $e->getMessage() . " at " . date('Y-m-d H:i:s') . "\n";
} finally {
    if ($mqtt->isConnected()) {
        $mqtt->disconnect();
        echo "Disconnected from broker at " . date('Y-m-d H:i:s') . "\n";
    }
}