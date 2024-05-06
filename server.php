<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/MyApp/Game.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use MyApp\Game;

$game = new Game();
$server = IoServer::factory(
    new HttpServer(
        new WsServer($game)
    ),
    9000
);

$server->run();
