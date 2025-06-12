<?php
if (!defined('ECLO')) die("Hacking attempt");

$jatbi = new Jatbi($app);
$setting = $app->getValueData('setting');

// ROUTE GET /employee (GIỮ NGUYÊN)
$app->router("/employee", 'GET', function($vars) use ($app, $jatbi, $setting) {
    $vars['title'] = $jatbi->lang('Quản lý nhân viên');
    echo $app->render('templates/camera/employee.html', $vars);
})->setPermissions(['employee']);


// CẬP NHẬT ROUTE POST /employee (THÊM CỘT CHECKBOX)
$app->router("/employee", 'POST', function($vars) use ($app, $jatbi) {
    $app->header(['Content-Type' => 'application/json']);

    // ... (code lấy tham số và xây dựng $where giữ nguyên như cũ) ...
    $draw = $_POST['draw'] ?? 0;
    $start = $_POST['start'] ?? 0;
    $length = $_POST['length'] ?? 10;
    $searchValue = $_POST['search']['value'] ?? '';
    $dateFrom = $_POST['date_from'] ?? '';
    $dateTo = $_POST['date_to'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $orderColumnIndex = $_POST['order'][0]['column'] ?? 1; // Bắt đầu từ cột SN
    $orderDir = strtoupper($_POST['order'][0]['dir'] ?? 'DESC');
    $validColumns = ["checkbox", "sn", "registration_photo", "person_name", "telephone", "gender", "birthday", "creation_time"];
    $orderColumn = $validColumns[$orderColumnIndex] ?? "creation_time";

    $conditions = [];
    if (!empty($searchValue)) { $conditions["OR"] = ["sn[~]" => $searchValue, "person_name[~]" => $searchValue, "telephone[~]" => $searchValue]; }
    if (!empty($dateFrom) && !empty($dateTo)) { $conditions["creation_time[<>]"] = [$dateFrom . " 00:00:00", $dateTo . " 23:59:59"]; }
    if ($gender !== '') { $conditions["gender"] = $gender; }

    $where = ["LIMIT" => [$start, $length], "ORDER" => [$orderColumn => $orderDir]];
    if (!empty($conditions)) { $where["AND"] = $conditions; }

    $totalRecords = $app->count("employee");
    $filteredRecords = $app->count("employee", !empty($conditions) ? ["AND" => $conditions] : []);
    $datas = $app->select("employee", "*", $where) ?? [];
    
    // Sửa lại phần định dạng dữ liệu để thêm checkbox
    $formattedData = array_map(function($data) use ($app, $jatbi) {
        $genderLabels = ["1" => $jatbi->lang("Nam"), "2" => $jatbi->lang("Nữ"), "3" => $jatbi->lang("Khác")];
        $photoHtml = $data['registration_photo'] ? '<img src="' . $data['registration_photo'] . '" alt="Photo" class="img-thumbnail" style="width: 60px; height: auto;">' : $jatbi->lang('Chưa có ảnh');

        return [
            // THÊM DÒNG NÀY ĐỂ TRẢ VỀ HTML CHO CHECKBOX
            "checkbox" => '<div class="form-check"><input class="form-check-input employee-checker" type="checkbox" value="' . $data['sn'] . '"></div>',
            "sn" => $data['sn'],
            "registration_photo" => $photoHtml,
            "person_name" => $data['person_name'],
            "telephone" => $data['telephone'],
            "gender" => $genderLabels[$data['gender']] ?? $jatbi->lang("Không xác định"),
            "birthday" => $data['birthday'] ? date('d/m/Y', strtotime($data['birthday'])) : 'N/A',
            "creation_time" => date('H:i:s d/m/Y', strtotime($data['creation_time'])),
            "action" => $app->component("action", [ /* ... action buttons ... */ ])
        ];
    }, $datas);

    echo json_encode(["draw" => intval($draw), "recordsTotal" => $totalRecords, "recordsFiltered" => $filteredRecords, "data" => $formattedData]);
})->setPermissions(['employee']);


$app->router("/employee-add", 'GET', function($vars) use ($app, $jatbi) {
    $vars['title'] = $jatbi->lang('Thêm nhân viên');
    echo $app->render('templates/camera/employee-post.html', $vars, 'global');
})->setPermissions(['employee']);


$app->router("/employee-add", 'POST', function($vars) use ($app, $jatbi) {
    $app->header(['Content-Type' => 'application/json']);

    // --- Bước 1: Lấy dữ liệu từ form ---
    $sn = $_POST['sn'] ?? '';
    $person_name = trim($_POST['person_name'] ?? '');
    $telephone = $_POST['telephone'] ?? null;
    $gender = $_POST['gender'] ?? null;
    $birthday = $_POST['birthday'] ?? null;
    $id_card = $_POST['id_card'] ?? null;
    $address = $_POST['address'] ?? null;

    // --- Bước 2: Kiểm tra dữ liệu (Validation) ---
    
    // 2.1. Kiểm tra tên nhân viên có được nhập hay không
    if (empty($person_name)) {
        $app->json(['status' => 'error', 'content' => $jatbi->lang('Vui lòng nhập họ và tên nhân viên.')]);
        return; // Dừng thực thi
    }

    // 2.2. Kiểm tra ảnh có được tải lên không
    if (!isset($_FILES['registration_photo']) || $_FILES['registration_photo']['error'] !== UPLOAD_ERR_OK) {
        $app->json(['status' => 'error', 'content' => $jatbi->lang('Vui lòng chọn ảnh đăng ký cho nhân viên.')]);
        return;
    }

    // 2.3. Kiểm tra kích thước file ảnh (phải nhỏ hơn 300KB)
    $max_size_kb = 300;
    if ($_FILES['registration_photo']['size'] > ($max_size_kb * 1024)) {
        $app->json(['status' => 'error', 'content' => $jatbi->lang('Kích thước ảnh không được vượt quá ' . $max_size_kb . 'KB.')]);
        return;
    }
    
    // 2.4. (Nên có) Kiểm tra định dạng file ảnh
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    $file_type = mime_content_type($_FILES['registration_photo']['tmp_name']);
    if (!in_array($file_type, $allowed_types)) {
        $app->json(['status' => 'error', 'content' => $jatbi->lang('Định dạng ảnh không hợp lệ. Chỉ chấp nhận JPG, PNG, GIF.')]);
        return;
    }

    // --- Bước 3: Xử lý Mã nhân viên (SN) ---
    // Nếu SN trống, tự động tạo một mã mới
    if (empty($sn)) {
        $sn = 'NV' . time(); // Ví dụ: NV1749708637
    }
    // Kiểm tra xem SN đã tồn tại trong database chưa
    if ($app->has("employee", ["sn" => $sn])) {
        $app->json(['status' => 'error', 'content' => $jatbi->lang('Mã nhân viên này đã tồn tại. Vui lòng chọn một mã khác.')]);
        return;
    }

    // --- Bước 4: Xử lý tải file ảnh lên ---
    $uploadDir = __DIR__ . '/../../public/uploads/photos/';
    // Tạo thư mục nếu nó chưa tồn tại
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Tạo tên file mới, duy nhất để tránh trùng lặp
    $fileExtension = pathinfo($_FILES['registration_photo']['name'], PATHINFO_EXTENSION);
    $newFileName = $sn . '_' . uniqid() . '.' . strtolower($fileExtension);
    $uploadFilePath = $uploadDir . $newFileName;
    
    // Đường dẫn để lưu vào database
    $dbImagePath = 'uploads/photos/' . $newFileName; 

    // Di chuyển file đã tải lên vào thư mục chỉ định
    if (!move_uploaded_file($_FILES['registration_photo']['tmp_name'], $uploadFilePath)) {
        $app->json(['status' => 'error', 'content' => $jatbi->lang('Đã có lỗi xảy ra khi tải ảnh lên. Vui lòng thử lại.')]);
        return;
    }

    try {
    // --- Bước 5: Thêm vào database ---
        $app->insert("employee", [
            "sn" => $sn,
            "person_name" => $person_name,
            "registration_photo" => $dbImagePath, // Lưu đường dẫn tương đối của ảnh
            "telephone" => $telephone,
            "gender" => $gender,
            "birthday" => empty($birthday) ? null : $birthday,
            "id_card" => $id_card,
            "address" => $address,
    ]);        
        echo json_encode(["status" => "success", "content" => $jatbi->lang("Thêm thành công")]);
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "content" => "Lỗi: " . $e->getMessage()]);
    }
})->setPermissions(['employee']); 


$app->router("/employee-delete", 'POST', function($vars) use ($app, $jatbi) {
    $list = explode(',', $_POST['list'] ?? '');
    
    if (empty($list) || empty($list[0])) {
        $app->json(['status' => 'error', 'content' => $jatbi->lang('Không có nhân viên nào được chọn.')]);
    }
    
    // Xóa khỏi database
    $app->delete("employee", [
        "sn" => $list
    ]);
    
    // Trả về thông báo thành công
    $app->json(['status' => 'success', 'content' => $jatbi->lang('Đã xóa các nhân viên được chọn.'), 'load' => 'this']);
})->setPermissions(['employee']);