// server.js (phiên bản cho 2 kênh)
const { createServer } = require('http');
const { Server } = require('socket.io');
const { createClient } = require('redis');

const PORT = 3000;
const httpServer = createServer();
const io = new Server(httpServer, {
    cors: { origin: "*" }
});

const redisClient = createClient();
redisClient.on('error', (err) => console.log('Redis Client Error', err));

(async () => {
    await redisClient.connect();
    console.log('✅ Đã kết nối Redis thành công.');

    // Lắng nghe cùng lúc 2 channel
    const channels = ['rec-events', 'snap-events'];
    
    await redisClient.subscribe(channels, (message, channel) => {
        console.log(`📨 Nhận được tin nhắn từ Redis channel [${channel}]`);
        const data = JSON.parse(message);

        // Kiểm tra xem tin nhắn đến từ channel nào để phát sự kiện tương ứng
        if (channel === 'rec-events') {
            io.emit('new_rec_event', data);
            console.log('✅ Đã emit sự kiện "new_rec_event".');
        } else if (channel === 'snap-events') {
            io.emit('new_snap_event', data);
            console.log('✅ Đã emit sự kiện "new_snap_event".');
        }
    });
    console.log(`📡 Đang lắng nghe trên các channel Redis: ${channels.join(', ')}`);
})();

io.on('connection', (socket) => {
    console.log(`Một client đã kết nối: ${socket.id}`);
});

httpServer.listen(PORT, () => {
    console.log(`🚀 WebSocket server đang chạy tại cổng ${PORT}`);
});