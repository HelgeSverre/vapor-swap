<?php

declare(strict_types=1);

namespace VaporSwap;

final class ProfileStore
{
    public function __construct(private readonly JsonFile $file)
    {
    }

    public static function validateName(string $name): void
    {
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$/D', $name)) {
            throw new \InvalidArgumentException('Profile names must be 1–64 letters, numbers, dots, underscores or hyphens, starting with a letter or number.');
        }
    }

    public function all(): array
    {
        return $this->profiles($this->file->read());
    }

    private function profiles(array $data): array
    {
        if ([] === $data) {
            return [];
        }
        if (($data['version'] ?? null) !== 1 || !is_array($data['profiles'] ?? null)) {
            throw new \RuntimeException('Unsupported profile configuration. Expected version 1 and a profiles object.');
        }
        $profiles = $data['profiles'];
        foreach ($profiles as $name => $profile) {
            self::validateName((string) $name);
            if (!is_array($profile) || !is_string($profile['token'] ?? null) || '' === $profile['token'] || !is_int($profile['team'] ?? null) || $profile['team'] <= 0) {
                throw new \RuntimeException('Invalid saved profile: '.$name);
            }
            foreach (['team_name', 'account_email'] as $key) {
                if (!is_string($profile[$key] ?? null)) {
                    throw new \RuntimeException('Invalid saved profile: '.$name);
                }
            }
        }
        ksort($profiles, SORT_NATURAL | SORT_FLAG_CASE);

        return $profiles;
    }

    public function get(string $name): array
    {
        return $this->all()[$name] ?? throw new \RuntimeException('Unknown profile "'.$name.'". Run vapor-swap list.');
    }

    public function save(string $name, array $profile, bool $replace): void
    {
        self::validateName($name);
        $this->file->update(function (array $data) use ($name, $profile, $replace): array {
            $profiles = $this->profiles($data);
            if (isset($profiles[$name]) && !$replace) {
                throw new \RuntimeException('Profile "'.$name.'" already exists. Use --replace to update it.');
            }
            $profiles[$name] = $profile;
            $data['version'] = 1;
            $data['profiles'] = (object) $profiles;

            return $data;
        });
    }

    public function remove(string $name): void
    {
        $this->file->update(function (array $data) use ($name): array {
            $profiles = $this->profiles($data);
            if (!isset($profiles[$name])) {
                throw new \RuntimeException('Unknown profile "'.$name.'". Run vapor-swap list.');
            }
            unset($profiles[$name]);
            $data['profiles'] = (object) $profiles;

            return $data;
        });
    }

    public function matching(array $config): array
    {
        return array_map(strval(...), array_keys(array_filter($this->all(), static fn (array $profile): bool => isset($config['token'], $config['team']) && hash_equals($profile['token'], (string) $config['token']) && $profile['team'] === (int) $config['team'])));
    }
}
