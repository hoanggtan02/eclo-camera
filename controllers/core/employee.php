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
    echo $app->render('templates/camera/employee-post.html', $vars); 
})->setPermissions(['employee']);


$app->router("/employee-add", 'POST', function($vars) use ($app, $jatbi) {
    // Lấy dữ liệu từ form
    $sn = $_POST['sn'] ?? '';
    $person_name = $_POST['person_name'] ?? '';
    // ... lấy các trường khác ...

    // Kiểm tra dữ liệu (validation)
    if (empty($sn) || empty($person_name)) {
        $app->json(['status' => 'error', 'content' => $jatbi->lang('Vui lòng nhập đầy đủ thông tin bắt buộc.')]);
    }

    // Thêm vào database
    $app->insert("employee", [
        "sn" => $sn,
        "person_name" => $person_name,
        // ... các trường khác ...
    ]);

    // Trả về thông báo thành công
    $app->json(['status' => 'success', 'content' => $jatbi->lang('Thêm nhân viên thành công.'), 'load' => 'this']);
})->setPermissions(['employee']);


$app->router("/employee-delete", 'GET', function($vars) use ($app, $jatbi) {
    $vars['title'] = $jatbi->lang('Xóa nhân viên');
    $vars['list'] = explode(',', $_GET['box'] ?? '');
    echo $app->render('templates/employee/delete.html', $vars); 
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