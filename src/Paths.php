<?php

declare(strict_types=1);

namespace VaporSwap;

final class Paths
{
    public readonly string $home;

    public function __construct(?string $home = null)
    {
        $this->home = $home ?? (getenv('HOME') ?: getenv('USERPROFILE') ?: throw new \RuntimeException('Cannot locate your home directory. Set HOME.'));
    }

    public function profiles(): string
    {
        return $this->home.'/.vapor-swap/config.json';
    }

    public function persistent(): string
    {
        return $this->home.'/.laravel-vapor/config.json';
    }

    public function session(): ?string
    {
        return getenv('VAPOR_SWAP_SESSION') ?: null;
    }

    public function newSession(): string
    {
        return $this->home.'/.vapor-swap/sessions/'.bin2hex(random_bytes(16)).'.json';
    }
}
