<?php

declare(strict_types=1);

namespace VaporSwap\Tests;

use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

final class InteractiveTest extends TempHomeTestCase
{
    public function testTokenStdinWorksWithoutPuttingTokenInArguments(): void
    {
        $result = $this->process([PHP_BINARY, 'tests/Fixtures/application.php', 'add', 'client', '--token-stdin', '--team', '2', '-n'], [], "fixture-secret-token\n");
        self::assertTrue($result->isSuccessful(), $result->getErrorOutput());
        self::assertSame('fixture-secret-token', $this->store->get('client')['token']);
        self::assertStringNotContainsString('fixture-secret-token', $result->getOutput().$result->getErrorOutput());
    }

    public function testInteractiveTeamSelection(): void
    {
        if (!Process::isPtySupported()) {
            self::markTestSkipped('PTY is unavailable');
        }
        $process = new Process([PHP_BINARY, 'tests/Fixtures/application.php', 'add', 'client', '--token', 'fixture-token'], dirname(__DIR__), ['HOME' => $this->home, 'CI' => 'false'], "1\n", 10);
        $process->setPty(true);
        $process->run();
        self::assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        self::assertSame(2, $this->store->get('client')['team']);
    }

    public function testHiddenPromptDoesNotEchoToken(): void
    {
        if (!Process::isPtySupported()) {
            self::markTestSkipped('PTY is unavailable');
        }
        $input = new InputStream();
        $process = new Process([PHP_BINARY, 'tests/Fixtures/application.php', 'add', 'client'], dirname(__DIR__), ['HOME' => $this->home, 'CI' => 'false', 'TEST_TEAMS' => '[{"id":1,"name":"Only"}]'], $input, 10);
        $process->setPty(true);
        $process->start();
        try {
            self::assertTrue($process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'Vapor API token:')));
            // Let the terminal enter hidden-input mode after writing its prompt.
            usleep(100000);
            $input->write("fixture-secret-token\n");
            $input->close();
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
            self::assertSame('fixture-secret-token', $this->store->get('client')['token']);
            self::assertStringNotContainsString('fixture-secret-token', $process->getOutput().$process->getErrorOutput());
        } finally {
            $process->stop();
        }
    }

    public function testInterruptingHiddenPromptDoesNotSaveAndReturns130(): void
    {
        if (!Process::isPtySupported() || !function_exists('pcntl_signal')) {
            self::markTestSkipped('PTY and signals are required');
        }
        $input = new InputStream();
        $process = new Process([PHP_BINARY, 'tests/Fixtures/application.php', 'add', 'client'], dirname(__DIR__), ['HOME' => $this->home, 'CI' => 'false'], $input, 10);
        $process->setPty(true);
        $process->start();
        try {
            self::assertTrue($process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'Vapor API token:')));
            usleep(100000);
            $process->signal(SIGINT);
            self::assertSame(130, $process->wait(), $process->getOutput().$process->getErrorOutput());
            self::assertFileDoesNotExist($this->paths->profiles());
        } finally {
            $process->stop();
        }
    }
}
