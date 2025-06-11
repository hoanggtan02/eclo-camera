<?php
if (!defined('ECLO')) die("Hacking attempt");

// Giả sử $app và $jatbi đã được khởi tạo từ file bootstrap của framework
$jatbi = new Jatbi($app);
$setting = $app->getValueData('setting'); // Lấy cài đặt chung, bao gồm cả cài đặt MQTT

// Route để hiển thị trang HTML
$app->router("/camera", 'GET', function($vars) use ($app) {
    echo $app->render('templates/camera/camera.html', $vars);
})->setPermissions(['camera']);

// Route để cung cấp dữ liệu JSON cho DataTable
$app->router("/camera", 'POST', function($vars) use ($app, $jatbi) {
    $app->header(['Content-Type' => 'application/json']);

    // Nhận dữ liệu từ DataTable
    $draw = $_POST['draw'] ?? 0;
    $start = $_POST['start'] ?? 0;
    $length = $_POST['length'] ?? 10;
    $searchValue = $_POST['search']['value'] ?? '';
    $type = $_POST['type'] ?? '';

    // Fix lỗi ORDER cột
    $orderColumnIndex = $_POST['order'][0]['column'] ?? 1; // Mặc định cột SN
    $orderDir = strtoupper($_POST['order'][0]['dir'] ?? 'DESC');
    
    $validColumns = ["checkbox", "sn", "name", "type", "department"];
    $orderColumn = $validColumns[$orderColumnIndex] ?? "sn";

    // Điều kiện lọc dữ liệu
    $where = [
        "AND" => [
            "OR" => [
                "employee.sn[~]" => $searchValue,
                "employee.name[~]" => $searchValue,
            ]
        ],
        "LIMIT" => [$start, $length],
        "ORDER" => [$orderColumn => $orderDir]
    ];

    if (!empty($type)) {
        $where["AND"]["employee.type"] = $type;
    }

    // Đếm tổng số bản ghi phù hợp điều kiện
    $count = $app->count("employee", ["AND" => $where["AND"]]);

    // Truy vấn danh sách nhân viên từ CSDL
    $datas = $app->select("employee", [
        "[>]department" => ["departmentId" => "departmentId"]
    ], [
        "department.personName",
        "employee.sn",
        "employee.name",
        "employee.type",
        "employee.status",
    ], $where) ?? [];

    // Xử lý dữ liệu để hiển thị
    $formattedData = array_map(function($data) use ($app, $jatbi) {
        $typeLabels = [
            "1" => $jatbi->lang("Nhân viên nội bộ"),
            "2" => $jatbi->lang("Khách"),
            "3" => $jatbi->lang("Danh sách đen"),
        ];

        return [
            "checkbox" => $app->component("box", ["data" => $data['sn']]),
            "sn" => $data['sn'],
            "name" => $data['name'],
            "type" => $typeLabels[$data['type']] ?? $jatbi->lang("Không xác định"),
            "department" => $data['personName'],
            "status" => $app->component("status", ["url" => "/employee-status/" . $data['sn'], "data" => $data['status'], "permission" => ['employee.edit']]),
            "action" => $app->component("action", [
                "button" => [
                    // Các nút action... (giữ nguyên)
                ]
            ]),
            "view" => '<a href="/manager/employee-detail?box=' . $data['sn'] . '" title="' . $jatbi->lang("Xem Chi Tiết") . '"><i class="ti ti-eye"></i></a>',
        ];
    }, $datas);

    // Trả về dữ liệu JSON cho DataTable
    echo json_encode([
        "draw" => intval($draw),
        "recordsTotal" => $count,
        "recordsFiltered" => $count,
        "data" => $formattedData
    ]);
})->setPermissions(['camera']);

// KHÔNG CÓ BẤT KỲ MÃ MQTT NÀO Ở ĐÂY
?>