
$(document).ready(function() {
    const $datatable = $('#datatable');
    const eventType = $datatable.data('eventType'); 

    if (!eventType) {
        console.error("Lỗi: Bảng không có thuộc tính 'data-event-type'.");
        return;
    }

    let apiEndpoint = '';
    let dtColumns = [];
    let dtColumnDefs = [];

    // --- Cấu hình riêng cho từng loại trang ---
    if (eventType === 'Rec') {
        apiEndpoint = '/camera/rec';
        dtColumns = [
            { "data": "id" }, { "data": "image_path" }, { "data": "person_name" },
            { "data": "person_id" }, { "data": "similarity" }, { "data": "event_time" },
            { "data": "person_type" }, { "data": "is_no_mask" }
        ];
        // Bạn có thể thêm columnDefs riêng cho trang Rec ở đây
    } else { // 'Snap'
        apiEndpoint = '/camera/snap';
        dtColumns = [
            { "data": "id" }, { "data": "image_path" },
            { "data": "event_time" }, { "data": "is_no_mask" }
        ];
         // Bạn có thể thêm columnDefs riêng cho trang Snap ở đây
    }

    // --- Khởi tạo DataTable với cấu hình động ---
    const datatable = $datatable.DataTable({
        "destroy": true,
        "processing": true,
        "serverSide": true,
        "ajax": { "url": apiEndpoint, "type": "POST" },
        "columns": dtColumns,
        "columnDefs": dtColumnDefs,
    });

    // --- Kết nối WebSocket và lắng nghe sự kiện động ---
    const socket = io('http://localhost:3000');
    socket.on('connect', () => {
        console.log(`✅ WebSocket đã kết nối. Trang này đang lắng nghe sự kiện cho: ${eventType}`);
    });

    const eventName = (eventType === 'Rec') ? 'new_rec_event' : 'new_snap_event';
    socket.on(eventName, (data) => {
        console.log(`Nhận được sự kiện ${eventName}:`, data);
        datatable.row.add(data).draw(false);
    });
});