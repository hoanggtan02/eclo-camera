<?php
require 'vendor/autoload.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

$server   = 'mqtts.eclm.io';
$port     = 1883; // Thay đổi nếu cần, tùy thuộc vào cấu hình broker
$clientId = 'eclo_' . time();
$username = 'eclo';
$password = 'Eclo@123';
$topic    = 'test-topic';

$mqtt = new MqttClient($server, $port, $clientId);

$connectionSettings = (new ConnectionSettings)
    ->setUsername($username)
    ->setPassword($password)
    ->setKeepAliveInterval(10);

$mqtt->connect($connectionSettings);
$mqtt->subscribe($topic, function ($message) {
    echo "Received message: {$message->getMessage()} on topic: {$message->getTopic()}\n";
    // Xử lý dữ liệu và hiển thị trên web tại đây
}, MqttClient::QOS_AT_LEAST_ONCE);

$mqtt->loop(true);
$mqtt->disconnect();