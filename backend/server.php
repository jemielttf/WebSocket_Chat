<?php
require 'vendor/autoload.php';
require 'RedisChat.php';

use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use Predis\Client as RedisClient;

$loop = Loop::get();
$redis = new RedisClient(['host' => 'redis']);

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new RedisChat($loop, $redis)
        )
    ),
    7654
);


$server->run();
echo "WebSocket Server started at ws://localhost:7654\n";