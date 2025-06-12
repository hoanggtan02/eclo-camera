// // Đợi cho trang web và các thư viện như jQuery, DataTable được tải hoàn toàn
// $(document).ready(function() {
//     // Chỉ thực thi code nếu trên trang có bảng dữ liệu với id="datatable"
//     if ($('#datatable').length === 0) {
//         return; // Dừng lại nếu đây không phải trang camera
//     }

//     // Lấy đối tượng DataTable đã được khởi tạo trên trang
//     var datatable = $('#datatable').DataTable();

//     // --- CẤU HÌNH KẾT NỐI MQTT ---
//     // !!! Đảm bảo các thông tin này chính xác !!!
//     const brokerUrl = 'wss://mqtt.ellm.io:8084/mqtt'; 
//     const topicToSubscribe = 'mqtt/face/1018656/+'; 
//     const options = {
//         connectTimeout: 4000,
//         clientId: 'browser_client_' + Math.random().toString(16).substr(2, 8),
//         username: 'eclo',
//         password: 'Eclo@123'
//     };

//     console.log('ws.js: Đang kết nối tới MQTT broker tại:', brokerUrl);
//     const client = mqtt.connect(brokerUrl, options);

//     // --- XỬ LÝ CÁC SỰ KIỆN KẾT NỐI ---

//     // Khi kết nối thành công
//     client.on('connect', function () {
//         console.log('✅ ws.js: Đã kết nối MQTT Broker qua WebSocket!');
//         client.subscribe(topicToSubscribe, function (err) {
//             if (!err) {
//                 console.log(`✅ ws.js: Đã đăng ký vào topic: ${topicToSubscribe}`);
//             } else {
//                 console.error('❌ ws.js: Lỗi khi đăng ký topic:', err);
//             }
//         });
//     });

//     // Khi có lỗi kết nối
//     client.on('error', function (err) {
//         console.error('❌ ws.js: Lỗi kết nối MQTT: ', err);
//         client.end();
//     });

//     // --- XỬ LÝ KHI NHẬN ĐƯỢC TIN NHẮN (QUAN TRỌNG NHẤT) ---
//     client.on('message', function (topic, message) {
//         const messageString = message.toString();
//         console.log(`📨 ws.js: Nhận được tin nhắn từ topic [${topic}]`);
        
//         try {
//             const payload = JSON.parse(messageString);
//             if (!payload.info) return;
//             const info = payload.info;
            
//             // --- LOGIC PHÂN LOẠI VÀ CẬP NHẬT BẢNG ---

//             // 1. Nếu là topic 'Rec' VÀ đang ở trang 'rec'
//             if (topic.endsWith('/Rec') && window.location.pathname.includes('/camera/rec')) {
//                 const personTypes = { "0": "Người lạ", "1": "Nhân viên", "2": "Khách", "3": "Danh sách đen" };
                
//                 datatable.row.add({
//                     "id": info.RecordID,
//                     "image_path": info.pic ? `<img src="${info.pic}" class="img-thumbnail" style="width:60px;">` : 'N/A',
//                     "person_name": info.persionName,
//                     "person_id": info.personId,
//                     "similarity": parseFloat(info.similarity1).toFixed(2) + '%',
//                     "event_time": new Date(info.time).toLocaleString('vi-VN'),
//                     "person_type": personTypes[info.PersonType] || "Không xác định",
//                     "is_no_mask": info.isNoMask == "1" ? '<span class="badge bg-danger">Không khẩu trang</span>' : '<span class="badge bg-success">Có khẩu trang</span>'
//                 }).draw(false); // draw(false) để vẽ lại bảng mà không reset trang
//             }

//             // 2. Nếu là topic 'Snap' VÀ đang ở trang 'snap'
//             else if (topic.endsWith('/Snap') && window.location.pathname.includes('/camera/snap')) {
//                 datatable.row.add({
//                     "id": info.SnapID,
//                     "image_path": info.pic ? `<img src="${info.pic}" class="img-thumbnail" style="width:60px;">` : 'N/A',
//                     "record_id": info.SnapID,
//                     "event_time": new Date(info.time).toLocaleString('vi-VN'),
//                     "is_no_mask": info.isNoMask == "1" ? '<span class="badge bg-danger">Không khẩu trang</span>' : '<span class="badge bg-success">Có khẩu trang</span>'
//                 }).draw(false);
//             }

//         } catch (e) {
//             console.error("❌ ws.js: Lỗi khi xử lý tin nhắn JSON:", e);
//         }
//     });
// });