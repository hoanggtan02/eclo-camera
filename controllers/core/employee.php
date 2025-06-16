<?php

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

// Đây là một kiểm tra an toàn phổ biến trong một số framework
// Nếu framework của bạn không sử dụng, bạn có thể bỏ comment dòng này
if (!defined('ECLO')) die("Hacking attempt");

// --- Khởi tạo các đối tượng toàn cục (giả định đã có từ file chính của bạn) ---
// Ví dụ: $app = new YourAppFramework();
$jatbi = new Jatbi($app);
$setting = $app->getValueData('setting');

// ROUTE GET /employee (Giữ nguyên)
$app->router("/employee", 'GET', function($vars) use ($app, $jatbi, $setting) {
    $vars['title'] = $jatbi->lang('Quản lý nhân viên');
    echo $app->render('templates/camera/employee.html', $vars);
})->setPermissions(['employee']);


// ROUTE POST /employee (Xử lý DataTables, cập nhật hiển thị checkbox)
$app->router("/employee", 'POST', function($vars) use ($app, $jatbi) {
    $app->header(['Content-Type' => 'application/json']);

    $draw = $_POST['draw'] ?? 0;
    $start = $_POST['start'] ?? 0;
    $length = $_POST['length'] ?? 10;
    $searchValue = $_POST['search']['value'] ?? '';
    $dateFrom = $_POST['date_from'] ?? '';
    $dateTo = $_POST['date_to'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $orderColumnIndex = $_POST['order'][0]['column'] ?? 1; 
    $orderDir = strtoupper($_POST['order'][0]['dir'] ?? 'DESC');
    $validColumns = ["checkbox", "sn", "registration_photo", "person_name", "telephone", "gender", "birthday", "creation_time"];
    $orderColumn = $validColumns[$orderColumnIndex] ?? "creation_time";

    $conditions = [];
    if (!empty($searchValue)) { 
        $conditions["OR"] = [
            "sn[~]" => $searchValue, 
            "person_name[~]" => $searchValue, 
            "telephone[~]" => $searchValue
        ]; 
    }
    if (!empty($dateFrom) && !empty($dateTo)) { 
        $conditions["creation_time[<>]"] = [$dateFrom . " 00:00:00", $dateTo . " 23:59:59"]; 
    }
    if ($gender !== '') { 
        $conditions["gender"] = $gender; 
    }

    $where = ["LIMIT" => [$start, $length], "ORDER" => [$orderColumn => $orderDir]];
    if (!empty($conditions)) { 
        $where["AND"] = $conditions; 
    }

    $totalRecords = $app->count("employee");
    $filteredRecords = $app->count("employee", !empty($conditions) ? ["AND" => $conditions] : []);
    $datas = $app->select("employee", "*", $where) ?? [];
    
    // Đọc .env để có APP_URL cho việc hiển thị ảnh trong DataTables
    $envPath = __DIR__ . '/../../.env';
    $env = file_exists($envPath) ? parse_ini_file($envPath) : [];
    $publicBaseUrlForDisplay = $env['APP_URL'] ?? 'http://localhost';

    // Định dạng dữ liệu để thêm checkbox và đường dẫn ảnh đầy đủ
    $formattedData = array_map(function($data) use ($app, $jatbi, $publicBaseUrlForDisplay) {
        $genderLabels = ["1" => $jatbi->lang("Nam"), "2" => $jatbi->lang("Nữ"), "3" => $jatbi->lang("Khác")];
        
        $imageSrc = $data['registration_photo'];
        if (!empty($imageSrc) && strpos($imageSrc, 'http') !== 0 && strpos($imageSrc, '//') !== 0) {
             // Chỉ thêm base URL nếu đường dẫn ảnh là tương đối
             $imageSrc = $publicBaseUrlForDisplay . '/' . $data['registration_photo'];
        }

        $photoHtml = $data['registration_photo'] ? '<img src="' . htmlspecialchars($imageSrc) . '" alt="Photo" class="img-thumbnail" style="width: 60px; height: auto;">' : $jatbi->lang('Chưa có ảnh');

        return [
            "checkbox" => '<div class="form-check"><input class="form-check-input employee-checker" type="checkbox" value="' . htmlspecialchars($data['sn']) . '"></div>',
            "sn" => htmlspecialchars($data['sn']),
            "registration_photo" => $photoHtml,
            "person_name" => htmlspecialchars($data['person_name']),
            "telephone" => htmlspecialchars($data['telephone']),
            "gender" => $genderLabels[$data['gender']] ?? $jatbi->lang("Không xác định"),
            "birthday" => $data['birthday'] ? date('d/m/Y', strtotime($data['birthday'])) : 'N/A',
            "creation_time" => date('H:i:s d/m/Y', strtotime($data['creation_time'])),
            "action" => $app->component("action", [ /* ... các nút hành động ... */ ]) // Cần định nghĩa component "action"
        ];
    }, $datas);

    echo json_encode(["draw" => intval($draw), "recordsTotal" => $totalRecords, "recordsFiltered" => $filteredRecords, "data" => $formattedData]);
})->setPermissions(['employee']);


// ROUTE GET /employee-add (Hiển thị form thêm)
$app->router("/employee-add", 'GET', function($vars) use ($app, $jatbi) {
    $vars['title'] = $jatbi->lang('Thêm nhân viên');
    echo $app->render('templates/camera/employee-post.html', $vars, 'global');
})->setPermissions(['employee']);


// ROUTE POST /employee-add (Xử lý thêm nhân viên và publish MQTT)
$app->router("/employee-add", 'POST', function($vars) use ($app, $jatbi) {
    $app->header(['Content-Type' => 'application/json']);

    $sn = $_POST['sn'] ?? '';
    $person_name = trim($_POST['person_name'] ?? '');
    $telephone = $_POST['telephone'] ?? null;
    $gender = $_POST['gender'] ?? null; 
    $birthday = $_POST['birthday'] ?? null;
    $id_card = $_POST['id_card'] ?? null;
    $address = $_POST['address'] ?? null;

    // --- Đọc .env để có cấu hình APP_URL và MQTT ---
    $envPath = __DIR__ . '/../../.env';
    if (!file_exists($envPath)) {
        error_log("Lỗi nghiêm trọng: File .env không được tìm thấy tại: $envPath");
        echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Lỗi cấu hình server. Vui lòng liên hệ quản trị.')]);
        return;
    }
    $env = parse_ini_file($envPath);
    // APP_URL này sẽ được dùng để tạo URL công khai cho ảnh
    $publicBaseUrl = $env['APP_URL'] ?? 'http://localhost'; 

    // --- Bắt đầu kiểm tra dữ liệu (Validation) ---
    // 2.1. Kiểm tra tên nhân viên
    if (empty($person_name)) {
        echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Vui lòng nhập họ và tên nhân viên.')]);
        return;
    }

    // 2.2. Kiểm tra ảnh có được tải lên không
    // if (!isset($_FILES['registration_photo']) || $_FILES['registration_photo']['error'] !== UPLOAD_ERR_OK) {
    //     echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Vui lòng chọn ảnh đăng ký cho nhân viên.')]);
    //     return;
    // }

    // 2.3. Kiểm tra kích thước file ảnh (phải nhỏ hơn 300KB)
    // $max_size_kb = 300;
    // if ($_FILES['registration_photo']['size'] > ($max_size_kb * 1024)) {
    //     echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Kích thước ảnh không được vượt quá ' . $max_size_kb . 'KB.')]);
    //     return;
    // }
    
    // 2.4. Kiểm tra định dạng file ảnh
    // Đảm bảo extension 'fileinfo' của PHP đã được kích hoạt trên server
    // if (!function_exists('mime_content_type')) {
    //     error_log("Lỗi: Extension 'fileinfo' của PHP không được kích hoạt. Không thể kiểm tra loại MIME.");
    //     echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Lỗi server: Thiếu extension cần thiết cho xử lý ảnh.')]);
    //     return;
    // }
    // $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    // $file_type = mime_content_type($_FILES['registration_photo']['tmp_name']);
    // if (!in_array($file_type, $allowed_types)) {
    //     echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Định dạng ảnh không hợp lệ. Chỉ chấp nhận JPG, PNG, GIF.')]);
    //     return;
    // }
    // --- Kết thúc kiểm tra dữ liệu (Validation) ---

    // --- Bước 3: Xử lý Mã nhân viên (SN) ---
    if (empty($sn)) {
        $sn = 'NV' . time(); // Ví dụ: NV1749708637
    }
    // Kiểm tra xem SN đã tồn tại trong database chưa
    if ($app->has("employee", ["sn" => $sn])) {
        echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Mã nhân viên này đã tồn tại. Vui lòng chọn một mã khác.')]);
        return;
    }

    // --- Bước 4: Xử lý tải file ảnh lên ---
    $uploadDir = __DIR__ . '/../../public/uploads/photos/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    $fileExtension = pathinfo($_FILES['registration_photo']['name'], PATHINFO_EXTENSION);
    $newFileName = $sn . '_' . uniqid() . '.' . strtolower($fileExtension);
    $uploadFilePath = $uploadDir . $newFileName;
    
    $dbImagePath = 'uploads/photos/' . $newFileName; 
    // Đây là URL công khai của ảnh được upload, sẽ được gửi qua MQTT
    $publicImageUrl = $publicBaseUrl . '/' . $dbImagePath; 

    // if (!move_uploaded_file($_FILES['registration_photo']['tmp_name'], $uploadFilePath)) {
    //     echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Đã có lỗi xảy ra khi tải ảnh lên. Vui lòng thử lại.')]);
    //     return;
    // }

    try {
        $app->insert("employee", [
            "sn" => $sn,
            "person_name" => $person_name,
            "registration_photo" => $dbImagePath, // Lưu đường dẫn tương đối vào DB
            "telephone" => $telephone,
            "gender" => $gender, // Lưu gender từ form (1, 2, 3) vào DB
            "birthday" => empty($birthday) ? null : $birthday,
            "id_card" => $id_card,
            "address" => $address,
            // creation_time sẽ tự động được thêm bởi TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ]); 

        // --- Bước 6: Publish tin nhắn MQTT để cập nhật real-time ---
        $mqttServer = $env['MQTT_HOST'] ?? 'mqtt.ellm.io';
        $mqttPort = (int)($env['MQTT_PORT'] ?? 1883);
        $mqttUsername = $env['MQTT_USERNAME'] ?? 'eclo';
        $mqttPassword = $env['MQTT_PASSWORD'] ?? 'Eclo@123';
        $mqttClientId = 'eclo-web-publisher-' . uniqid();
        $mqttTopic = 'mqtt/face/1018656'; 

        try {
            $mqtt = new MqttClient($mqttServer, $mqttPort, $mqttClientId);
            $connectionSettings = (new ConnectionSettings)
                ->setUsername($mqttUsername)
                ->setPassword($mqttPassword)
                ->setConnectTimeout(5); 
            $mqtt->connect($connectionSettings, true);

            $mqttGender = null;
            if ($gender === '1') { 
                $mqttGender = 0;
            } elseif ($gender === '2') { 
                $mqttGender = 1;
            }
            $mqttPayload = [
                "messageId" => uniqid(),
                "operator" => "EditPerson",
                "info" => [
                    "customId" => $sn,
                    "name" => $person_name,
                    "gender" => $mqttGender, 
                    "birthday" => $birthday ?? "", 
                    "address" => $address ?? "",
                    "idCard" => $id_card ?? "", 
                    "telnum1" => $telephone ?? "", 
                    "personType" =>$gender, 
                    "picURI" => "https://demo.sfit.vn/images/customers/z6700531755512_62215e7fdfcc4756e8cc56f37d48e1eb_5.jpg", 
                ]
            ];
            
            $mqtt->publish($mqttTopic, json_encode($mqttPayload), 0); // QoS 0: gửi một lần, không đảm bảo nhận được
            $mqtt->disconnect();
            error_log("✅ Đã publish tin nhắn MQTT EditPerson cho SN: " . $sn . " với picURI: " . $publicImageUrl);

        } catch (Exception $e) {
            // Lỗi khi publish MQTT không nên ngăn việc thêm nhân viên vào DB
            error_log("❌ Lỗi khi publish MQTT từ employee-add: " . $e->getMessage());
        }
        
        echo json_encode(["status" => "success", "content" => $jatbi->lang("Thêm nhân viên thành công")]);

    } catch (Exception $e) {
        // Nếu có lỗi DB, xóa ảnh đã tải lên (nếu có) để tránh ảnh rác
        if (file_exists($uploadFilePath)) {
            unlink($uploadFilePath);
        }
        echo json_encode(["status" => "error", "content" => "Lỗi khi thêm nhân viên vào database: " . $e->getMessage()]);
    }
})->setPermissions(['employee']);


// ROUTE POST /employee-delete (Xóa nhân viên)
$app->router("/employee-delete", 'POST', function($vars) use ($app, $jatbi) {
    $list = explode(',', $_POST['list'] ?? '');
    
    if (empty($list) || empty($list[0])) {
        echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Không có nhân viên nào được chọn.')]);
        return;
    }
    
    try {
        // Lấy đường dẫn ảnh trước khi xóa khỏi database
        $employeesToDelete = $app->select("employee", ["sn", "registration_photo"], ["sn" => $list]);

        // Xóa khỏi database
        $app->delete("employee", [
            "sn" => $list
        ]);

        // Sau khi xóa khỏi DB, tiến hành xóa ảnh vật lý để tránh ảnh rác
        foreach ($employeesToDelete as $emp) {
            if (!empty($emp['registration_photo'])) {
                $filePath = __DIR__ . '/../../public/' . $emp['registration_photo'];
                if (file_exists($filePath) && is_file($filePath)) {
                    unlink($filePath);
                    error_log("Đã xóa ảnh vật lý: " . $filePath);
                }
            }
        }
        
        echo json_encode(['status' => 'success', 'content' => $jatbi->lang('Đã xóa các nhân viên được chọn.'), 'load' => 'this']);

    } catch (Exception $e) {
        error_log("❌ Lỗi khi xóa nhân viên: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'content' => $jatbi->lang('Đã có lỗi xảy ra khi xóa nhân viên: ') . $e->getMessage()]);
    }
})->setPermissions(['employee']);