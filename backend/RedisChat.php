<?php
date_default_timezone_set('Asia/Tokyo');


use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use Predis\Client as Redis;
use Clue\React\Redis\RedisClient;

class RedisChat implements MessageComponentInterface
{
	protected $clients;
	protected $redis;
	protected $redis_publisher;
	protected $redis_subscriber;
	protected $loop;

	protected $msg_id = 0;
	protected $SESSION_LIFETIME 		= 259200;
	protected $SESSION_CLEANUP_INTERVAL = 60;

	public function __construct(LoopInterface $loop) {
		$this->clients = new \SplObjectStorage;
		$this->loop = $loop;
		$this->redis = new Redis(['host' => 'redis']);

		echo "__construct :: Init RedisChat\n";
	}

	public function onOpen(ConnectionInterface $conn) {
		// Redisへの接続を確立
		if (empty($this->redis_publisher)) {
			$this->redis_publisher = new RedisClient('redis');
			$this->redis_publisher->set('greeting', "Hello! Redis");

			// Redis チャンネルを購読
			$this->subscribeToRedis();

			// 定期的なセッションのクリーンナップ
			Loop::addPeriodicTimer($this->SESSION_CLEANUP_INTERVAL, function() {
				$this->session_cleanup();
			});
		}

		// セッションIDの生成・取得
		$sessionId = $this->generateSessionId($conn);

		$this->clients->attach($conn);
		$this->clients->attach($conn, ['session_id' => $sessionId]);
		echo "New connection! (Client ID: {$conn->resourceId})\n";
		echo "New connection! (Session ID: {$sessionId})\n";
		echo "------------------\n";

		// セッションIDの登録の有無に関わらずHSETでクライアントIDの登録 or 更新をする。
		$this->redis_publisher->hset('sessions', $sessionId, $conn->resourceId)->then(
			function () use ($conn, $sessionId) {
				$this->redis_publisher->hexists('users', $sessionId)->then(
					function ($exists) use ($conn) {
						$sessionId = $this->clients[$conn]['session_id'];
						echo "User exists {$sessionId} : {$exists}\n";

						$msg_logs = $this->redis->lrange("chat_history", -100, -1);
						if (!empty($msg_logs)) {
							foreach($msg_logs as $msg_log) {
								$data = json_decode($msg_log, true);

								if ($data['type'] == 'message') {
									$conn->send($msg_log);
								}
							}
						}

						if (empty($exists)) {
							// クライアントに接続情報を送信
							$conn->send(json_encode([
								'id'            => $this->msg_id,
								'timestamp'     => date("Y-m-d\TH:i:sO"),
								'type'          => 'session_init',
								'resource_id'   => $conn->resourceId,
								'session_id'	=> $sessionId,
								'error'			=> 0,
							]));
							$this->msg_id++;
						} else {
							$this->redis_publisher->hget('users', $sessionId)->then(
								function ($user_name) use ($conn, $sessionId) {
									$this->sendUserNameMessage($conn, $user_name, $sessionId);
								}
							);
						}
					})->catch(function(\Exception $e) {
						echo "ERROR!! : {$e->getMessage()}\n";
					});
			}
		);

		$this->updateSessionLastActiveTime($sessionId);
	}

	public function onMessage(ConnectionInterface $from, $msg) {
		$msg 	= json_decode($msg);
		$msg_id = $this->msg_id;

		$sessionId = $this->clients[$from]['session_id'];

		switch ($msg->type) {
			case 'user_name':
				$this->redis_publisher->hset('users', $sessionId, $msg->user_name)->then(
					function() use ($from, $msg, $sessionId) {
						$this->sendUserNameMessage($from, $msg->user_name, $sessionId);
						$this->updateSessionLastActiveTime($sessionId);
					},

					function(\Exception $e) use ($from, $msg, $sessionId) {
						$this->sendUserNameMessage($from, $msg->user_name, $sessionId, $e);
					}
				);
				break;

			case 'message':
				$this->redis_publisher->hget('users', $sessionId)->then(
					function ($user_name) use ($from, $msg, $msg_id, $sessionId) {
						if (empty($user_name)) {
							$this->connectionClose($from);
							return;
						}

						$data = [
							'id'            => $msg_id,
							'timestamp'     => date("Y-m-d\TH:i:sO"),
							'type'          => 'message',
							'resource_id'   => $from->resourceId,
							'session_id'	=> $sessionId,
							'user_name'     => $user_name,
							'message'       => $msg->message,
							'error'			=> 0,
						];
						$this->msg_id++;

						$this->publishToRedis($data);
						$this->updateSessionLastActiveTime($sessionId);
					}
				);
				break;

			default:
				$data = [
					'id'            => $this->msg_id,
					'type'          => 'message',
					'error'			=> 0,
				];
		}
	}

	public function onClose(ConnectionInterface $conn) {
		$this->sendDisconnectMessage($conn);

		$this->clients->detach($conn);
		$this->redis->hdel('sessions', $this->clients[$conn]['session_id']);
		echo "Connection {$conn->resourceId} has disconnected\n";
	}

	public function onError(ConnectionInterface $conn, \Exception $e) {
		echo "Error: {$e->getMessage()}\n";
		$this->connectionClose($conn, $e->getMessage());
	}

	private function generateSessionId(ConnectionInterface $conn) {
		$queryString = $conn->httpRequest->getUri()->getQuery();
		parse_str($queryString, $query);
		echo "query['session_id'] : {$query['session_id']}\n";
		echo "------------------\n";

		// 既存セッションIDのチェック
		if (!empty($query['session_id'])) {
			$sessionId = $query['session_id'];
			if ($this->validateSession($sessionId)) {
				return $sessionId;
			}
		}

		// 新規セッションID生成
		$newSessionId = bin2hex(random_bytes(16));
		$this->redis->hset('sessions', $newSessionId, $conn->resourceId);
		echo "New session created: $newSessionId\n";

		return $newSessionId;
	}

	private function validateSession($sessionId) {
		return $this->redis->hexists('active_sessions', $sessionId);
	}

	private function updateSessionLastActiveTime($sessionId) {
		$this->redis->hset('active_sessions', $sessionId, time());
	}

	protected function subscribeToRedis() {
		echo "call :: subscribeToRedis()\n";

		$this->redis_subscriber = new RedisClient('redis');
		$this->redis_subscriber->subscribe('chat_channel');

		$this->redis_subscriber->on('message', function (string $channel, string $payload) {
			// pubsub message received on given $channel

			switch($channel) {
				case 'chat_channel' :
					$this->broadcast(json_decode($payload));
					break;
			}
		});

		echo "Subscribing to Redis channel\n";
	}

	protected function publishToRedis($data) {
		$json = json_encode($data);

		// Redis にメッセージをパブリッシュ
		$this->redis_publisher->publish('chat_channel', $json);

		// 履歴を保存
		$this->redis->rpush("chat_history", $json);
		$this->redis->ltrim("chat_history", -100, -1);

		$debug_message = array_key_exists('message', $data) ? $data['message'] : '';
		echo "Chat message from ({$data['resource_id']}): {$debug_message}\n";
		echo "------------------\n";
	}

	protected function connectionClose(ConnectionInterface $conn, $message = null) {
		$this->sendDisconnectMessage($conn, $message);
		$conn->close();
	}

	protected function sendDisconnectMessage(ConnectionInterface $conn, $message = null) {
		$sessionId = $this->clients[$conn]['session_id'];
		$user_name = $this->redis->hget('users', $sessionId);

		if (empty($user_name)) return;

		$data = [
			'id'            => $this->msg_id,
			'timestamp'     => date("Y-m-d\TH:i:sO"),
			'type'          => 'disconnected',
			'resource_id'   => $conn->resourceId,
			'session_id'	=> $sessionId,
			'user_name'     => $user_name,
			'error'			=> 0,
		];
		$this->msg_id++;

		if (!empty($message)) $data['message'] = $message;

		$conn->send(json_encode($data));
		$this->publishToRedis($data);
	}

	protected function sendUserNameMessage(ConnectionInterface $conn, $user_name, $sessionId, \Exception $e = null) {
		$data = [
			'id'            => $this->msg_id,
			'timestamp'     => date("Y-m-d\TH:i:sO"),
			'type'          => 'user_name',
			'resource_id'   => $conn->resourceId,
			'session_id'	=> $sessionId,
			'user_name'     => $user_name,
			'error'			=> 0,
		];
		$this->msg_id++;

		if ($e !== null) {
			$data['error'] = 1;
			$data['message'] = $e->getMessage();
		}

		$this->publishToRedis($data);
	}

	protected function broadcast($message) {
		// Now $message is an object, you can access its properties
		echo "Received message:\n";
		print_r($message);
		echo "------------------\n";

		// Send the message to all connected clients
		foreach ($this->clients as $client) {
			$client->send(json_encode($message));
		}
	}

	protected function session_cleanup() {
		$sessions = $this->redis->hgetall('active_sessions');

		if (empty($sessions)) return;

		echo "------------------\n";
		echo "セッションのクリーンナップ\n";
		print_r($sessions);
		echo "------------------\n";

		foreach($sessions as $sessionId => $lastActivity) {
			if (time() - $lastActivity > $this->SESSION_LIFETIME) {
				$client_id = $this->redis->hget('sessions', $sessionId);

				// セッションIDの有効期限切れ接続を強制切断
				foreach($this->clients as $client) {
					if ($client->resourceId == $client_id) {
						$this->connectionClose($client);
					}
				}

				$this->redis->hdel('sessions', $sessionId);
				$this->redis->hdel('users', $sessionId);
				$this->redis->hdel('active_sessions', $sessionId);

				echo "Deleted Session ID : {$sessionId}\n";
			}
		}
		echo "------------------\n";

		$sessions = $this->redis->hgetall('active_sessions');
		if (empty($sessions)) {
			$this->redis->del('sessions');
			$this->redis->del('users');
		}
	}
}
