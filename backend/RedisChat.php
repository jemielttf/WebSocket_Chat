<?php

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use React\EventLoop\LoopInterface;
use Predis\Client as RedisClient;

class RedisChat implements MessageComponentInterface
{
    protected $clients;
    protected $redis;
    protected $loop;

    public function __construct(LoopInterface $loop, RedisClient $redis) {
        $this->clients = new \SplObjectStorage;
        $this->loop = $loop;
        $this->redis = $redis;

        $this->redis->set('greeting', 'Hello Redis');
        echo $this->redis->get('greeting') . "\n";

        // Redis チャンネルを購読
        $this->subscribeToRedis();
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "New connection! ({$conn->resourceId})\n";

        // クライアントに接続情報を送信
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

        // Redis にメッセージをパブリッシュ
        $this->redis->publish('chat_channel', json_encode($data));

		// 履歴を保存
		$this->redis->rpush("chat_history", json_encode($data));
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

    protected function subscribeToRedis() {
        echo "call :: subscribeToRedis()\n";

        $redis = new RedisClient(['host' => 'redis']);
        $pubsub = $redis->pubSubLoop();
        $pubsub->subscribe('chat_channel');

        $this->loop->addPeriodicTimer(1, function() use ($pubsub) {
            foreach ($pubsub as $message) {
                if ($message->kind === 'message') {
                    $this->handleMessage($message->payload);
                }
            }
        });

        echo "Subscribing to Redis channel\n";
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