<?php

declare(strict_types=1);

namespace VaporSwap\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\ExecutableFinder;
use VaporSwap\JsonFile;
use VaporSwap\Shell;

final class ShellTest extends TempHomeTestCase
{
    public static function shells(): array
    {
        return [['bash'], ['zsh'], ['fish']];
    }

    private function runShell(string $shell, string $script, array $env = []): \Symfony\Component\Process\Process
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('Shell integration supports macOS/Linux');
        }
        $binary = (new ExecutableFinder())->find($shell);
        if (null === $binary) {
            self::markTestSkipped($shell.' is not installed');
        }
        $file = $this->home.'/commands.'.$shell;
        file_put_contents($file, Shell::integration($shell, dirname(__DIR__).'/bin/vapor-swap')."\n".$script);
        $args = 'fish' === $shell ? [$binary, '--no-config', $file] : [$binary, '--noprofile', '--norc', $file];
        if ('zsh' === $shell) {
            $args = [$binary, '-f', $file];
        }

        return $this->process($args, array_merge(['VAPOR_SWAP_VAPOR_BINARY' => __DIR__.'/Fixtures/vapor'], $env));
    }

    #[DataProvider('shells')]
    public function testSessionVaporUsesSelectedConfigAndPreservesHome(string $shell): void
    {
        $this->profile();
        (new JsonFile($this->paths->persistent()))->update(static fn (): array => ['token' => 'persistent-token', 'team' => 1]);
        $before = file_get_contents($this->paths->persistent());
        $result = $this->runShell($shell, <<<'SH'
vapor-swap swap client
vapor 'argument with spaces' '--flag=x'
vapor set-team 7
vapor-swap current --json
SH);
        self::assertTrue($result->isSuccessful(), $result->getErrorOutput());
        $lines = explode("\n", trim($result->getOutput()));
        $first = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('test-client-token', $first['token']);
        self::assertSame(50346, $first['team']);
        self::assertSame($this->home, $first['home']);
        self::assertSame(['argument with spaces', '--flag=x'], $first['arguments']);
        $second = json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(7, $second['team']);
        self::assertStringContainsString('"source": "session"', $result->getOutput());
        self::assertStringContainsString('"team": 7', $result->getOutput());
        self::assertSame($before, file_get_contents($this->paths->persistent()));
    }

    #[DataProvider('shells')]
    public function testStickyClearsVariablesAndResetReturnsToPersistentConfig(string $shell): void
    {
        $this->profile();
        $this->profile('personal', 'personal-token', 10);
        $script = <<<'SH'
vapor-swap swap client
vapor-swap swap personal --sticky
vapor-swap current --json
vapor-swap --quiet swap client
vapor-swap reset
vapor-swap current --json
SH;
        $script .= 'fish' === $shell
            ? '\nset -q VAPOR_API_TOKEN; and exit 21\nset -q VAPOR_SWAP_SESSION; and exit 22\ntrue\n'
            : '\n[ -z "${VAPOR_API_TOKEN+x}" ] || exit 21\n[ -z "${VAPOR_SWAP_SESSION+x}" ] || exit 22\n';
        $result = $this->runShell($shell, str_replace('\\n', "\n", $script));
        self::assertTrue($result->isSuccessful(), $result->getErrorOutput());
        self::assertSame(2, substr_count($result->getOutput(), '"source": "persistent"'));
        self::assertSame('personal-token', (new JsonFile($this->paths->persistent()))->read()['token']);
    }

    #[DataProvider('shells')]
    public function testQuotingDoesNotExecuteTokenContents(string $shell): void
    {
        $marker = $this->home.'/injected';
        $token = 'test\'"\\$(touch${IFS}'.$marker.');tail';
        $this->profile('client', $token);
        $result = $this->runShell($shell, "vapor-swap swap client\nvapor\n");
        self::assertTrue($result->isSuccessful(), $result->getErrorOutput());
        self::assertSame($token, json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR)['token']);
        self::assertFileDoesNotExist($marker);
    }

    #[DataProvider('shells')]
    public function testFailedSwapAndHelpDoNotAlterSession(string $shell): void
    {
        $this->profile();
        $script = 'fish' === $shell ? <<<'FISH'
vapor-swap swap client
if vapor-swap swap missing
    exit 40
end
vapor-swap swap client --help >/dev/null
vapor
FISH : <<<'SH'
vapor-swap swap client
if vapor-swap swap missing; then exit 40; fi
vapor-swap swap client --help >/dev/null
vapor
SH;
        $result = $this->runShell($shell, $script);
        self::assertTrue($result->isSuccessful(), $result->getErrorOutput());
        self::assertSame('test-client-token', json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR)['token']);
    }

    public function testSeparateShellsKeepSeparateSelections(): void
    {
        $this->profile();
        $this->profile('personal', 'personal-token', 10);
        $one = $this->runShell('bash', "vapor-swap swap client\nvapor\n");
        $two = $this->runShell('bash', "vapor-swap swap personal\nvapor\n");
        self::assertSame('test-client-token', json_decode($one->getOutput(), true)['token']);
        self::assertSame('personal-token', json_decode($two->getOutput(), true)['token']);
        self::assertFileDoesNotExist($this->paths->persistent());
        self::assertCount(2, glob($this->home.'/.vapor-swap/sessions/*.json'));
    }

    public function testMissingSessionFailsClosedInsteadOfUsingPersistentCredentials(): void
    {
        $result = $this->cli(['__vapor', 'list'], ['VAPOR_SWAP_SESSION' => $this->home.'/missing.json']);
        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('Session config is missing', $result->getErrorOutput());
    }

    public function testSessionAdapterSupportsNestedConfigurationWrites(): void
    {
        $this->profile();
        $result = $this->runShell('bash', "vapor-swap swap client\nvapor nested\n");
        self::assertTrue($result->isSuccessful(), $result->getErrorOutput());
        self::assertSame("changed\n", $result->getOutput());
    }

    #[DataProvider('shells')]
    public function testFailedStickyWritePreservesSessionAndBrokenFile(string $shell): void
    {
        $this->profile();
        (new JsonFile($this->paths->persistent()))->update(static fn (): array => ['token' => 'old', 'team' => 1]);
        $script = 'fish' === $shell ? <<<'FISH'
vapor-swap swap client
printf '{broken' > "$HOME/.laravel-vapor/config.json"
if vapor-swap swap client --sticky
    exit 40
end
vapor
FISH : <<<'SH'
vapor-swap swap client
printf '{broken' > "$HOME/.laravel-vapor/config.json"
if vapor-swap swap client --sticky; then exit 40; fi
vapor
SH;
        $result = $this->runShell($shell, $script);
        self::assertTrue($result->isSuccessful(), $result->getErrorOutput());
        self::assertSame('test-client-token', json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR)['token']);
        self::assertSame('{broken', file_get_contents($this->paths->persistent()));
    }
}
