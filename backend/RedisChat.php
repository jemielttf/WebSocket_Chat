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

	public function __construct(LoopInterface $loop) {
		$this->clients = new \SplObjectStorage;
		$this->loop = $loop;

		echo "__construct :: Init RedisChat\n";
	}

	public function onOpen(ConnectionInterface $conn) {
		// セッションIDの生成・取得
		$sessionId = $this->generateSessionId($conn);

		$this->clients->attach($conn);
		echo "New connection! (Client ID: {$conn->resourceId})\n";
		echo "New connection! (Session ID: {$sessionId})\n";

		if (empty($this->redis_publisher)) {
			$this->redis_publisher = new RedisClient('redis');
			$this->redis_publisher->set('greeting', "Hello! Redis");

			// Redis チャンネルを購読
			$this->subscribeToRedis();
		}

		$this->redis_publisher->hexists('sessions', $sessionId)->then(function ($exists) use ($conn, $sessionId) {
			if (!$exists) {
				// セッション ID が Redis に存在しない場合、新規登録
				$this->redis_publisher->hset('sessions', $sessionId, json_encode([
					'resource_id' => $conn->resourceId,
					'created_at' => time()
				]))->then(function () use ($conn, $sessionId) {
					echo "New session created: $sessionId\n";

					// クライアントに接続情報を送信
					$conn->send(json_encode([
						'id'            => $this->msg_id,
						'type'          => 'session_init',
						'resource_id'   => $conn->resourceId,
						'session_id'	=> $sessionId,
					]));
					$this->msg_id++;
				});
			} else {
				echo "Existing session found: $sessionId\n";

				// クライアントに接続情報を送信
				$conn->send(json_encode([
					'id'            => $this->msg_id,
					'type'          => 'connection',
					'resource_id'   => $conn->resourceId,
					'resource_id'	=> $sessionId,
				]));
				$this->msg_id++;
			}
		});
	}

	public function onMessage(ConnectionInterface $from, $msg) {
		$msg 	= json_decode($msg);
		$msg_id = $this->msg_id;

		switch ($msg->type) {
			case 'user_name':
				$this->redis_publisher->hset('users', $from->resourceId, $msg->user_name)->then(function() use ($from, $msg, $msg_id) {
					$data = [
						'id'            => $msg_id,
						'type'          => 'user_name',
						'resource_id'   => $from->resourceId,
						'user_name'     => $msg->user_name,
						'error'			=> 0,
					];

					$this->publishToRedis($data);
				}, function(\Exception $e) use ($from, $msg, $msg_id) {
					$data = [
						'id'            => $msg_id,
						'type'          => 'user_name',
						'resource_id'   => $from->resourceId,
						'user_name'     => $msg->user_name,
						'error'			=> 1,
						'error_info'	=> $e->getMessage(),
					];
					$this->publishToRedis($data);
				});
				break;

			case 'message':
				$this->redis_publisher->hget('users', $from->resourceId)->then(function ($user_name) use ($from, $msg, $msg_id) {
					$data = [
						'id'            => $msg_id,
						'type'          => 'message',
						'resource_id'   => $from->resourceId,
						'user_name'     => $user_name,
						'message'       => $msg->message,
						'error'			=> 0,
					];

					$this->publishToRedis($data);
				});
				break;

			default:
				$data = [
					'id'            => $this->msg_id,
					'type'          => 'message',
					'error'			=> 0,
				];
		}
		$this->msg_id++;
	}

	public function onClose(ConnectionInterface $conn) {
		$this->clients->detach($conn);
		$this->redis_publisher->hdel('users', $conn->resourceId);
		echo "Connection {$conn->resourceId} has disconnected\n";
	}

	public function onError(ConnectionInterface $conn, \Exception $e) {
		echo "Error: {$e->getMessage()}\n";
		$conn->close();
	}

	private function generateSessionId(ConnectionInterface $conn) {
		$queryString = $conn->httpRequest->getUri()->getQuery();
		echo "queryString : {$queryString}\n";
		echo "------------------\n";

		parse_str($queryString, $query);

		// 既存セッションIDのチェック
		if (!empty($query['session_id'])) {
			$sessionId = $query['session_id'];
			if ($this->validateSession($sessionId)) {
				return $sessionId;
			}
		}

		// 新規セッションID生成
		$newSessionId = bin2hex(random_bytes(16));
		return $newSessionId;
	}

	private function validateSession($sessionId) {
		return $this->redis_publisher->hexists('sessions', $sessionId);
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

	protected function publishToRedis($data) {
		$json = json_encode($data);

		// Redis にメッセージをパブリッシュ
		$this->redis_publisher->publish('chat_channel', $json);

		// 履歴を保存
		$this->redis_publisher->rpush("chat_history", $json);
		$this->redis_publisher->ltrim("chat_history", -100, -1);

		echo "------------------\n";
		$debug_message = $data['message'] ? $data['message'] : '';
		echo "Chat message from ({$data['resource_id']}): {$debug_message}\n";
		echo "------------------\n";
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
