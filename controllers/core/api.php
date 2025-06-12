<?php
// File: controllers/core/api.php

// Bước 1: Khởi tạo môi trường của framework
if (!defined('ECLO')) {
    define('ECLO', true);
}
require __DIR__ . '/../includes/config.php';

// Bước 2: Lấy đối tượng Medoo từ framework của bạn
// Giả định rằng $app->db() trả về đối tượng Medoo đã được khởi tạo.
// Nếu không, bạn hãy thay bằng phương thức đúng, ví dụ: $jatbi->db()
/** @var Medoo\Medoo $db */
$db = $app->db(); 

try {
    $lastId = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;

    $newData = $db->select('mqtt_messages', [
        'id',
        'person_name',
        'person_id',
        'similarity',
        'received_at'
    ], [
        'id[>]' => $lastId,
        'ORDER' => ['id' => 'DESC']
    ]);
    
    // Bước 5: Trả kết quả về dạng JSON
    header('Content-Type: application/json');
    echo json_encode($newData);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'An error occurred.', 'message' => $e->getMessage()]);
}