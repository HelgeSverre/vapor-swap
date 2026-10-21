<?php

declare(strict_types=1);

namespace VaporSwap\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VaporSwap\VaporApi;

final class ApiTest extends TestCase
{
    public function testMergesOwnedAndMemberTeamsAndDeduplicatesById(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"id":1,"email":"test@example.test"}'),
            new Response(200, [], '[{"id":3,"name":"Zulu"},{"id":2,"name":"Alpha"}]'),
            new Response(200, [], '[{"id":3,"name":"Zulu"},{"id":4,"name":"Beta"}]'),
        ]));
        $stack->push(Middleware::history($history));
        $api = new VaporApi(new Client(['handler' => $stack, 'base_uri' => 'https://vapor.laravel.com']));
        $result = $api->discover('fixture-token');
        self::assertSame([2, 4, 3], array_keys($result['teams']));
        self::assertSame(['/api/user', '/api/owned-teams', '/api/teams'], array_map(static fn (array $item): string => $item['request']->getUri()->getPath(), $history));
        foreach ($history as $item) {
            self::assertSame('Bearer fixture-token', $item['request']->getHeaderLine('Authorization'));
            self::assertFalse($item['options']['allow_redirects']);
        }
    }

    public static function errors(): array
    {
        return [[401, 'rejected'], [403, 'permission'], [429, 'rate limit'], [500, 'HTTP 500'], [302, 'HTTP 302']];
    }

    #[DataProvider('errors')]
    public function testApiErrorsNeverEchoResponseBodiesOrTokens(int $status, string $message): void
    {
        $api = new VaporApi(new Client(['handler' => HandlerStack::create(new MockHandler([new Response($status, [], 'secret-response-token')]))]));
        try {
            $api->discover('secret-request-token');
            self::fail('Request should fail');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString($message, $error->getMessage());
            self::assertStringNotContainsString('secret', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }

    public static function invalidResponses(): array
    {
        return [
            'invalid JSON' => [['not-json'], 'invalid JSON'],
            'scalar account' => [['null'], 'unexpected response'],
            'missing account email' => [['{"id":1}'], 'account response'],
            'team object instead of list' => [['{"id":1,"email":"test@example.test"}', '{"data":[]}'], 'team list'],
            'invalid team ID' => [['{"id":1,"email":"test@example.test"}', '[{"id":0,"name":"Invalid"}]'], 'team response'],
            'missing team name' => [['{"id":1,"email":"test@example.test"}', '[{"id":1}]'], 'team response'],
        ];
    }

    #[DataProvider('invalidResponses')]
    public function testRejectsMalformedApiResponses(array $bodies, string $message): void
    {
        $responses = array_map(static fn (string $body): Response => new Response(200, [], $body), $bodies);
        $api = new VaporApi(new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        $api->discover('fixture-token');
    }

    public function testConnectionErrorsDoNotExposeRequestDetails(): void
    {
        $failure = new ConnectException('Failed with secret-transport-details', new Request('GET', 'https://vapor.laravel.com/api/user'));
        $api = new VaporApi(new Client(['handler' => HandlerStack::create(new MockHandler([$failure]))]));
        try {
            $api->discover('secret-token');
            self::fail('Connection should fail');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Cannot reach the Vapor API', $error->getMessage());
            self::assertStringNotContainsString('secret', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }
}
