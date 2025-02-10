<?php
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use Predis\Client as RedisClient;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

require 'vendor/autoload.php';

class ChatServer implements MessageComponentInterface {
    protected $clients;
    protected $redis;
    protected $loop;

    public function __construct() {
        $this->clients = new \SplObjectStorage();
        $this->redis = new RedisClient(['host' => 'redis']); // Redis サーバーのホスト名を指定
        $this->loop = Loop::get(); // イベントループを取得

        $this->redis->set('greeting', 'Hello Redis');
        echo $this->redis->get('greeting') . "\n";

        // イベントループの次のサイクルで Redis の購読を開始
        // $this->loop->futureTick(function () {
        //     echo "futureTick :: Subscribing to Redis channel\n";

        //     $this->subscribeToRedis();
        // });

        $this->subscribeToRedis();
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "New connection! ({$conn->resourceId})\n";

        $conn->send(json_encode([
            'type' => 'connection',
            'resource_id' => $conn->resourceId,
            'greeting' => $this->redis->get('greeting')
        ]));
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = [
            'type' => 'message',
            'resource_id' => $from->resourceId,
            'message' => $msg
        ];

        // クライアントからのメッセージを Redis に publish
        $message = json_encode($data);
        $this->redis->publish("chat_channel", $message);

        // 履歴を保存
		$this->redis->rpush("chat_history", $message);
		$this->redis->ltrim("chat_history", -100, -1);

        echo "Chat message from ({$data['resource_id']}): {$data['message']}\n";
    }


    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "Error: {$e->getMessage()}\n";
        $conn->close();
    }

    private function subscribeToRedis() {
        echo "subscribeToRedis :: Subscribing to Redis channel\n";

        $redis = new RedisClient(['host' => 'redis']); // 購読専用の Redis クライアント
        // $redis->subscribe(['chat_channel'], function ($message) {
        //     foreach ($this->clients as $client) {
        //         $client->send($message);
        //     }
        // });

        $pubsub = $redis->pubSubLoop();
        $pubsub->subscribe('chat_channel');

        $this->loop->addPeriodicTimer(1, function() use ($pubsub) {
            echo "addPeriodicTimer :: Listening for messages from Redis channel\n";

            foreach ($pubsub as $message) {
                if ($message->kind === 'message') {
                    echo "Received message from Redis: {$message->payload}\n";
                    $this->handleMessage($message->payload);
                }
            }
        });
    }

    public function handleMessage($message) {
        // Decode JSON message to an object
        $message = json_decode($message);

        // Now $message is an object, you can access its properties
        echo "Received message: {$message->message}\n";

        // Send the message to all connected clients
        foreach ($this->clients as $client) {
            $client->send(json_encode($message));
        }
    }
}

// WebSocket サーバーの起動 (ポート 7654)
$server = IoServer::factory(
    new HttpServer(new WsServer(new ChatServer())),
    7654
);
$server->run();
echo "WebSocket Server started at ws://localhost:7654\n";