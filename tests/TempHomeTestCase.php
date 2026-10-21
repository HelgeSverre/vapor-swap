<?php

declare(strict_types=1);

namespace VaporSwap\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use VaporSwap\JsonFile;
use VaporSwap\Paths;
use VaporSwap\ProfileStore;

abstract class TempHomeTestCase extends TestCase
{
    protected string $home;
    protected Paths $paths;
    protected ProfileStore $store;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/vapor-swap-test-'.bin2hex(random_bytes(8));
        mkdir($this->home, 0700);
        $this->paths = new Paths($this->home);
        $this->store = new ProfileStore(new JsonFile($this->paths->profiles()));
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->home);
    }

    private function deleteDirectory(string $path): void
    {
        foreach (new \FilesystemIterator($path) as $item) {
            if ($item->isDir() && !$item->isLink()) {
                $this->deleteDirectory($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }

    protected function profile(string $name = 'client', string $token = 'test-client-token', int $team = 50346): void
    {
        $this->store->save($name, ['token' => $token, 'team' => $team, 'team_name' => ucfirst($name), 'account_email' => 'test@example.test'], false);
    }

    protected function process(array $command, array $env = [], ?string $input = null): Process
    {
        $process = new Process($command, dirname(__DIR__), array_merge([
            'HOME' => $this->home,
            'USERPROFILE' => $this->home,
            'VAPOR_SWAP_SESSION' => false,
            'VAPOR_SWAP_PROFILE' => false,
            'VAPOR_API_TOKEN' => false,
            'VAPOR_SWAP_VAPOR_BINARY' => false,
            'VAPOR_API_BASE' => false,
            'CI' => 'true',
        ], $env), $input, 15);
        $process->run();

        return $process;
    }

    protected function cli(array $arguments, array $env = [], ?string $input = null): Process
    {
        return $this->process(array_merge([PHP_BINARY, 'bin/vapor-swap'], $arguments), $env, $input);
    }
}
