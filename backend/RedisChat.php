<?php

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
			Loop::addPeriodicTimer(60, function () {
				echo "------------------\n";
				echo "セッションのクリーンナップ\n";
				echo "------------------\n";

				$this->redis_publisher->hgetall('active_sessions')->then(
					function ($sessions) {
						print_r($sessions);
						echo "------------------\n";

						for ($i = 0; $i < count($sessions); $i += 2) {
							$sessionId 		= $sessions[$i];
							$lastActivity	= $sessions[$i + 1];

							if (time() - $lastActivity > 180) {
								$this->redis_publisher->hdel('sessions', $sessionId);
								$this->redis_publisher->hdel('users', $sessionId);
								$this->redis_publisher->hdel('active_sessions', $sessionId);
							}
						}
					}
				)->catch(function(\Exception $e) {
					echo "ERROR!! : {$e->getMessage()}\n";
				});
			});
		}

		// セッションIDの生成・取得
		$sessionId = $this->generateSessionId($conn);

		$this->clients->attach($conn);
		$this->clients->attach($conn, ['session_id' => $sessionId]);
		echo "New connection! (Client ID: {$conn->resourceId})\n";
		echo "New connection! (Session ID: {$sessionId})\n";

		// セッションIDの登録の有無に関わらずHSETでクライアントIDの登録 or 更新をする。
		$this->redis_publisher->hset('sessions', $sessionId, $conn->resourceId)->then(
			function () use ($conn, $sessionId) {
				$this->redis_publisher->hexists('users', $sessionId)->then(
					function ($exists) use ($conn) {
						$sessionId = $this->clients[$conn]['session_id'];

						if (empty($exists)) {
							// クライアントに接続情報を送信
							$conn->send(json_encode([
								'id'            => $this->msg_id,
								'type'          => 'session_init',
								'resource_id'   => $conn->resourceId,
								'session_id'	=> $sessionId,
								'error'			=> 0,
							]));
						} else {
							$this->redis_publisher->hget('users', $sessionId)->then(
								function ($user_name) use ($conn) {
									$data = [
										'id'            => $this->msg_id,
										'type'          => 'user_name',
										'resource_id'   => $conn->resourceId,
										'user_name'     => $user_name,
										'error'			=> 0,
									];

									$this->publishToRedis($data);
								}
							);
						}
						$this->msg_id++;
						$this->updateSessionLastActiveTime($sessionId);
					})->catch(function(\Exception $e) {
						echo "ERROR!! : {$e->getMessage()}\n";
					});
			}
		);
	}

	public function onMessage(ConnectionInterface $from, $msg) {
		$msg 	= json_decode($msg);
		$msg_id = $this->msg_id;

		$sessionId = $this->clients[$from]['session_id'];

		switch ($msg->type) {
			case 'user_name':
				$this->redis_publisher->hset('users', $sessionId, $msg->user_name)->then(function() use ($from, $msg, $msg_id, $sessionId) {
					$data = [
						'id'            => $msg_id,
						'type'          => 'user_name',
						'resource_id'   => $from->resourceId,
						'user_name'     => $msg->user_name,
						'error'			=> 0,
					];

					$this->publishToRedis($data);
					$this->updateSessionLastActiveTime($sessionId);
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
				$this->redis_publisher->hget('users', $sessionId)->then(function ($user_name) use ($from, $msg, $msg_id, $sessionId) {
					if (empty($user_name)) {
						$from->close();
						return;
					}

					$data = [
						'id'            => $msg_id,
						'type'          => 'message',
						'resource_id'   => $from->resourceId,
						'user_name'     => $user_name,
						'message'       => $msg->message,
						'error'			=> 0,
					];

					$this->publishToRedis($data);
					$this->updateSessionLastActiveTime($sessionId);
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
		$this->redis_publisher->hset('sessions', $newSessionId, $conn->resourceId)->then(function () use ($newSessionId) {
			echo "New session created: $newSessionId\n";
		});

		return $newSessionId;
	}

	private function validateSession($sessionId) {
		return $this->redis->hexists('sessions', $sessionId);
	}

	private function updateSessionLastActiveTime($sessionId) {
		$this->redis_publisher->hset('active_sessions', $sessionId, time());
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
		$this->redis_publisher->rpush("chat_history", $json);
		$this->redis_publisher->ltrim("chat_history", -100, -1);

		echo "------------------\n";
		$debug_message = array_key_exists('message', $data) ? $data['message'] : '';
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
