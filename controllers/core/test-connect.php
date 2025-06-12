<?php
// File: test_connect.php
require __DIR__ . '/../../vendor/autoload.php';
use Simps\MQTT\Client;

$config = [
    'host' => 'mqtt.eclo.io',
    'port' => 1883,
    'user_name' => 'eclo',
    'password' => 'Eclo@123',
    'client_id' => 'test-simps-mqtt-' . uniqid(),
    'keep_alive' => 10,
];

try {
    echo "Dang thu ket noi voi thu vien Simps/MQTT...\n";
    $client = new Client($config);
    $client->connect();
    echo "KET NOI THANH CONG! Voi thu vien Simps/MQTT.\n";
    $client->close();
} catch (Throwable $e) {
    echo "KET NOI THAT BAI: " . $e->getMessage() . "\n";
}