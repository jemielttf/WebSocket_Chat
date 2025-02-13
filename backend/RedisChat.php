<?php

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use React\EventLoop\LoopInterface;
use Clue\React\Redis\RedisClient;

class RedisChat implements MessageComponentInterface
{
    protected $clients;
    protected $redis_publisher;
    protected $redis_subscriber;
    protected $loop;

    protected $msg_id = 0;
    protected $user_name_dic = [];


    public function __construct(LoopInterface $loop) {
        $this->clients = new \SplObjectStorage;
        $this->loop = $loop;

        echo "__construct :: Init RedisChat\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);

        echo "New connection! ({$conn->resourceId})\n";

        if (empty($this->redis_publisher)) {
            $this->redis_publisher = new RedisClient('redis');
            $this->redis_publisher->set('greeting', "Hello! Redis");

            // Redis チャンネルを購読
            $this->subscribeToRedis();
        }

        $this->redis_publisher->get('greeting')->then(function($value) use($conn) {
            echo "{$this->msg_id} : {$value}\n";

            // クライアントに接続情報を送信
            $conn->send(json_encode([
                'id'            => $this->msg_id,
                'type'          => 'connection',
                'resource_id'   => $conn->resourceId,
                'greeting'      => $value,
            ]));
            $this->msg_id++;
        }, function (Exception $e) {
            echo 'Error: ' . $e->getMessage() . PHP_EOL;
        });
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $msg = json_decode($msg);

        switch($msg->type) {
            case 'user_name' :
                $data = [
                    'id'            => $this->msg_id,
                    'type'          => 'user_name',
                    'resource_id'   => $from->resourceId,
                    'user_name'     => $msg->user_name,
                ];
                $this->user_name_dic[$from->resourceId] = $msg->user_name;
                break;

            case 'message' :
                $data = [
                    'id'            => $this->msg_id,
                    'type'          => 'message',
                    'resource_id'   => $from->resourceId,
                    'user_name'     => $this->user_name_dic[$from->resourceId],
                    'message'       => $msg->message,
                ];
                break;

            default :
                $data = [
                    'id'            => $this->msg_id,
                    'type'          => 'message',
                ];
        }
        $this->msg_id++;

        $json = json_encode($data);

        // Redis にメッセージをパブリッシュ
        $this->redis_publisher->publish('chat_channel', $json);

		// 履歴を保存
        $this->redis_publisher->rpush("chat_history", $json);
		$this->redis_publisher->ltrim("chat_history", -100, -1);


        echo "------------------\n";
		echo "Chat message from ({$data['resource_id']}): {$data['message']}\n";
        echo "------------------\n";
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

        $this->redis_subscriber = new RedisClient('redis');
        $this->redis_subscriber->subscribe('chat_channel');

        $this->redis_subscriber->on('message', function (string $channel, string $payload) {
            // pubsub message received on given $channel
            var_dump($channel, json_decode($payload));
            echo "------------------\n";

            $this->broadcast(json_decode($payload));
        });

        echo "Subscribing to Redis channel\n";
    }

    public function broadcast($message) {
        // Now $message is an object, you can access its properties
        echo "Received message:\n";
        print_r($message);
        echo "------------------\n";

        // Send the message to all connected clients
        foreach ($this->clients as $client) {
            $client->send(json_encode($message));
        }
    }
}