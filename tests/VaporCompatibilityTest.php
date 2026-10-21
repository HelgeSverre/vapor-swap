<?php

declare(strict_types=1);

namespace VaporSwap\Tests;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use VaporSwap\JsonFile;
use VaporSwap\Shell;

final class VaporCompatibilityTest extends TempHomeTestCase
{
    public function testRealVaporReadsSessionTokenAndTeamAndStickyDefault(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('Shell integration supports macOS/Linux');
        }
        $vapor = getenv('TEST_VAPOR_BINARY') ?: (new ExecutableFinder())->find('vapor');
        if (!$vapor) {
            self::markTestSkipped('Set TEST_VAPOR_BINARY to an installed laravel/vapor-cli executable');
        }
        if (!(new ExecutableFinder())->find('bash')) {
            self::markTestSkipped('Bash is required for the shell compatibility test');
        }
        $this->profile();
        $this->profile('personal', 'personal-token', 10);
        (new JsonFile($this->paths->persistent()))->update(static fn (): array => ['token' => 'personal-token', 'team' => 10]);
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($listener, $error);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $server = new Process([PHP_BINARY, '-S', $address, __DIR__.'/Fixtures/api-router.php'], dirname(__DIR__));
        $server->start();
        try {
            $ready = false;
            for ($attempt = 0; $attempt < 100; ++$attempt) {
                $socket = @stream_socket_client('tcp://'.$address, $errno, $error, 0.05);
                if (false !== $socket) {
                    fclose($socket);
                    $ready = true;
                    break;
                }
                usleep(20000);
            }
            self::assertTrue($ready, $server->getErrorOutput());
            $script = Shell::integration('bash', dirname(__DIR__).'/bin/vapor-swap')."\nset -e\n".<<<'SH'
vapor --version
vapor-swap swap client
vapor team:current
vapor team:list
vapor-swap swap personal --sticky
vapor team:current
vapor-swap current --json
SH;
            $process = $this->process(['bash', '--noprofile', '--norc', '-c', $script], ['VAPOR_SWAP_VAPOR_BINARY' => $vapor, 'VAPOR_API_BASE' => 'http://'.$address]);
            self::assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
            self::assertStringContainsString('[Client]', $process->getOutput());
            self::assertStringContainsString('[Personal]', $process->getOutput());
            self::assertStringContainsString('50346', $process->getOutput());
            self::assertStringContainsString('"source": "persistent"', $process->getOutput());
            self::assertStringNotContainsString('#!/usr/bin/env', $process->getOutput());
            self::assertSame('personal-token', (new JsonFile($this->paths->persistent()))->read()['token']);
        } finally {
            $server->stop();
        }
    }
}
