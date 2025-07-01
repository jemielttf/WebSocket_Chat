import { WebSocket as MockWebSocket, Server } from 'mock-socket';
import { vi } from 'vitest';

// 1) グローバルに差し替え
vi.stubGlobal('WebSocket', MockWebSocket);

// 2) サーバ生成
export const mockServer = new Server('ws://localhost:7654');

// 3) 接続ごとのステート
const userMap = new Map();  // socket → { resource_id, user_name }
let nextId = 1;

// 4) 接続イベント
mockServer.on('connection', socket => {
  // --- テスト用にクライアント送信ログを保持 ---
  socket.sent = [];
  const _origSend = socket.send.bind(socket);
  socket.send = data => {
    socket.sent.push(data);   // ログへ保存
    _origSend(data);          // 元の send を実行
  };
  // --------------------------------------------

  // 4-1) ハンドシェイク
  socket.send(JSON.stringify({
    type: 'session_init',
    session_id: `TEST_SESSION`,
  }));

  // 4-2) クライアント → サーバ
  socket.on('message', data => {
    const pkt = JSON.parse(data);

    switch (pkt.type) {
      case 'user_name': {
        // 保存して全員に通知
        const info = { resource_id: `U${nextId}`, user_name: pkt.user_name, id: nextId++ };
        userMap.set(socket, info);
        broadcast({ type:'user_name', ...info });
        break;
      }
      case 'message': {
        const info = userMap.get(socket) || { resource_id: 'UNKNOWN', user_name: '???' };
        broadcast({ type:'message', ...info, id: nextId++, message: pkt.message });
        break;
      }
      default:
        // 想定外は無視
    }
  });
});

// 5) ブロードキャスト関数
function broadcast(obj) {
  mockServer.clients().forEach(c => c.send(JSON.stringify(obj)));
}

// 6) テスト前に呼ぶ用のリセット
mockServer.resetHandlers = () => {
  nextId = 1;
  userMap.clear();
  mockServer.clients().forEach(c => {
    if (Array.isArray(c.sent)) c.sent.length = 0;  // ログをクリア
  });
};