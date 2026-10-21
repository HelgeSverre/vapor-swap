<?php

declare(strict_types=1);

$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$team = match ($token) {
    'Bearer test-client-token' => ['id' => 50346, 'name' => 'Client'],
    'Bearer personal-token' => ['id' => 10, 'name' => 'Personal'],
    default => null,
};
header('Content-Type: application/json');
if (null === $team) {
    http_response_code(401);
    echo '{"message":"Invalid fixture credentials"}';

    return;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ('GET' !== $_SERVER['REQUEST_METHOD']) {
    http_response_code(405);
    echo '{}';

    return;
}
echo match ($path) {
    '/api/user' => '{"id":1,"name":"Test","email":"test@example.test"}',
    '/api/owned-teams' => json_encode([array_merge($team, ['owner' => ['id' => 1, 'name' => 'Test']])]),
    '/api/teams' => '[]',
    default => '{}',
};
