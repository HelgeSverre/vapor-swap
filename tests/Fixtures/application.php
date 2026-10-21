<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use VaporSwap\Application;
use VaporSwap\VaporApi;

$teams = getenv('TEST_TEAMS') ?: '[{"id":1,"name":"Alpha"},{"id":2,"name":"Beta"}]';
$handler = HandlerStack::create(new MockHandler([
    new Response(200, [], '{"id":1,"email":"test@example.test"}'),
    new Response(200, [], $teams),
    new Response(200, [], '[]'),
]));
exit((new Application(api: new VaporApi(new Client(['handler' => $handler]))))->run());
