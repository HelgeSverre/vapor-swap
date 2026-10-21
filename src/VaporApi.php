<?php

declare(strict_types=1);

namespace VaporSwap;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

final class VaporApi
{
    public function __construct(private ?ClientInterface $client = null)
    {
    }

    public function discover(#[\SensitiveParameter] string $token): array
    {
        $user = $this->get('/api/user', $token);
        if (!isset($user['id']) || !is_string($user['email'] ?? null)) {
            throw new \RuntimeException('Vapor returned an unexpected account response.');
        }
        $teams = [];
        foreach (['/api/owned-teams', '/api/teams'] as $endpoint) {
            $items = $this->get($endpoint, $token);
            if (!array_is_list($items)) {
                throw new \RuntimeException('Vapor returned an unexpected team list.');
            }
            foreach ($items as $team) {
                if (!is_array($team) || !is_int($team['id'] ?? null) || $team['id'] <= 0 || !is_string($team['name'] ?? null)) {
                    throw new \RuntimeException('Vapor returned an unexpected team response.');
                }
                $teams[$team['id']] = $team;
            }
        }
        uasort($teams, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']) ?: $a['id'] <=> $b['id']);

        return ['user' => $user, 'teams' => $teams];
    }

    private function get(string $path, #[\SensitiveParameter] string $token): array
    {
        $this->client ??= new Client(['base_uri' => 'https://vapor.laravel.com']);
        try {
            $response = $this->client->request('GET', $path, [
                'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token],
                'connect_timeout' => 5,
                'timeout' => 15,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
        } catch (GuzzleException) {
            throw new \RuntimeException('Cannot reach the Vapor API. Check your connection and try again.');
        }
        $status = $response->getStatusCode();
        if (401 === $status) {
            throw new \RuntimeException('Vapor rejected this API token. Check that it is valid and has not been revoked.');
        }
        if (403 === $status) {
            throw new \RuntimeException('This API token does not have permission to read the Vapor account or teams.');
        }
        if (429 === $status) {
            throw new \RuntimeException('Vapor API rate limit reached. Try again later.');
        }
        if (200 !== $status) {
            throw new \RuntimeException('Vapor API request failed (HTTP '.$status.'). Try again later.');
        }
        try {
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Vapor returned invalid JSON. Try again later.');
        }
        if (!is_array($data)) {
            throw new \RuntimeException('Vapor returned an unexpected response.');
        }

        return $data;
    }
}
