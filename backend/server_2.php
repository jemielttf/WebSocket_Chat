<?php
require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use React\EventLoop\Loop;
use React\Socket\SocketServer;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Clue\React\Redis\Factory as RedisFactory;

class ChatServer implements MessageComponentInterface {
    protected $clients;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "新しい接続: ({$conn->resourceId})\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        echo "受信: {$msg}\n";
        // Redisにメッセージを送信
        $this->redis->publish('chat_channel', $msg);
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        echo "接続切断: ({$conn->resourceId})\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "エラー: {$e->getMessage()}\n";
        $conn->close();
    }

    public function broadcast($msg) {
        foreach ($this->clients as $client) {
            $client->send($msg);
        }
    }
}

// イベントループの取得
$loop = Loop::get();

$server = new ChatServer();

// 非同期Redisクライアントの作成
$redisFactory = new RedisFactory($loop);
$redisFactory->createLazyClient('redis://redis:6379')->then(function ($redis) use ($server) {
    echo "Redis に接続しました。\n";
    $server->redis = $redis; // WebSocketサーバー内で利用

    // Redis の Pub/Sub チャンネルを購読
    $redis->subscribe('chat_channel')->then(null, null, function ($message) use ($server) {
        echo "Redis から受信: {$message}\n";
        $server->broadcast($message);
    });
});

// WebSocketサーバーの作成
$socket = new SocketServer('0.0.0.0:7654', [], $loop);
$wsServer = new IoServer(new HttpServer(new WsServer($server)), $socket, $loop);

echo "WebSocketサーバーが起動しました (ws://localhost:7654)\n";
$loop->run();