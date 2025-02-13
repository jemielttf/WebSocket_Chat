<?php
require 'vendor/autoload.php';
require 'RedisChat.php';

use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;

$loop = Loop::get();

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new RedisChat($loop)
        )
    ),
    7654
);

$server->run();
echo "WebSocket Server started at ws://localhost:7654\n";