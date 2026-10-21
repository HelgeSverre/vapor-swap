<?php

declare(strict_types=1);

namespace VaporSwap\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\Console\Tester\ApplicationTester;
use VaporSwap\Application;
use VaporSwap\JsonFile;
use VaporSwap\VaporApi;

final class CommandsTest extends TempHomeTestCase
{
    private function add(array $arguments, array $teams, array $members = [], int $status = 200): ApplicationTester
    {
        $responses = [new Response($status, [], '{"id":1,"email":"test@example.test"}'), new Response(200, [], json_encode($teams)), new Response(200, [], json_encode($members))];
        $api = new VaporApi(new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));
        $app = new Application(null, $this->paths, $api);
        $app->setAutoExit(false);
        $tester = new ApplicationTester($app);
        $tester->run(array_merge(['command' => 'add', 'name' => 'client', '--token' => 'fixture-token', '--no-interaction' => true], $arguments), ['interactive' => false, 'capture_stderr_separately' => true]);

        return $tester;
    }

    public function testSingleTeamAutoSelectsAndDoesNotActivate(): void
    {
        $tester = $this->add([], [['id' => 50346, 'name' => 'Client']]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(50346, $this->store->get('client')['team']);
        self::assertFileDoesNotExist($this->paths->persistent());
    }

    public function testExplicitTeamCanComeFromMembership(): void
    {
        $tester = $this->add(['--team' => '2'], [['id' => 1, 'name' => 'Owned']], [['id' => 2, 'name' => 'Member']]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(2, $this->store->get('client')['team']);
    }

    public function testMultipleTeamsRequireExplicitSelectionWithoutTty(): void
    {
        $tester = $this->add([], [['id' => 1, 'name' => 'One'], ['id' => 2, 'name' => 'Two']]);
        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('--team', $tester->getErrorOutput());
        self::assertFileDoesNotExist($this->paths->profiles());
    }

    public function testZeroTeamsAndInaccessibleTeamsDoNotSave(): void
    {
        self::assertNotSame(0, $this->add([], [])->getStatusCode());
        self::assertNotSame(0, $this->add(['--team' => '5'], [['id' => 1, 'name' => 'One']])->getStatusCode());
        self::assertFileDoesNotExist($this->paths->profiles());
    }

    public function testFailedReplacementPreservesOriginal(): void
    {
        $this->profile();
        $tester = $this->add(['--replace' => true], [], [], 401);
        self::assertNotSame(0, $tester->getStatusCode());
        self::assertSame('test-client-token', $this->store->get('client')['token']);
    }

    public function testSuccessfulReplacementUpdatesTokenAndTeam(): void
    {
        $this->profile();
        self::assertSame(0, $this->add(['--replace' => true], [['id' => 7, 'name' => 'New']])->getStatusCode());
        self::assertSame('fixture-token', $this->store->get('client')['token']);
        self::assertSame(7, $this->store->get('client')['team']);
    }

    public function testListAndCurrentMatchActualConfigAndNeverExposeTokens(): void
    {
        $this->profile();
        (new JsonFile($this->paths->persistent()))->update(static fn (): array => ['token' => 'test-client-token', 'team' => 50346]);
        $list = $this->cli(['list', '--json']);
        self::assertTrue($list->isSuccessful(), $list->getErrorOutput());
        self::assertStringNotContainsString('test-client-token', $list->getOutput());
        self::assertTrue(json_decode($list->getOutput(), true)[0]['persistent']);
        $current = $this->cli(['current', '--json']);
        self::assertSame(['client'], json_decode($current->getOutput(), true)['profiles']);
        (new JsonFile($this->paths->persistent()))->update(static fn (): array => ['token' => 'changed-externally', 'team' => 8]);
        self::assertSame([], json_decode($this->cli(['current', '--json'])->getOutput(), true)['profiles']);
    }

    public function testMissingIntegrationDoesNotWriteSessionOrPersistentConfig(): void
    {
        $this->profile();
        $swap = $this->cli(['swap', 'client']);
        self::assertFalse($swap->isSuccessful());
        self::assertStringContainsString('shell integration', $swap->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->home.'/.vapor-swap/sessions');
        self::assertFileDoesNotExist($this->paths->persistent());
    }

    public function testRemoveDoesNotRevokeOrClearAuthentication(): void
    {
        $this->profile();
        $config = ['token' => 'test-client-token', 'team' => 50346];
        (new JsonFile($this->paths->persistent()))->update(static fn (): array => $config);
        self::assertTrue($this->cli(['remove', 'client'])->isSuccessful());
        self::assertSame([], $this->store->all());
        self::assertSame($config, (new JsonFile($this->paths->persistent()))->read());
    }

    public function testInvalidArgumentsAndMissingTokensFailWithoutWriting(): void
    {
        foreach ([['add', 'client', '-n'], ['add', 'client', '--token', 'x', '--token-stdin'], ['add', '../escape', '--token', 'x'], ['add', 'client', '--token', 'x', '--team', 'oops'], ['swap', 'unknown'], ['init', 'unknown']] as $arguments) {
            self::assertFalse($this->cli($arguments)->isSuccessful(), implode(' ', $arguments));
        }
        self::assertFileDoesNotExist($this->paths->profiles());
    }

    public function testHelpAndVersionAndCompletionWorkWithoutConfig(): void
    {
        foreach ([['--help'], ['--version'], ['add', '--help'], ['swap', '--help'], ['completion', 'bash'], ['completion', 'fish'], ['completion', 'zsh']] as $arguments) {
            $result = $this->cli($arguments);
            self::assertTrue($result->isSuccessful(), $result->getErrorOutput());
            self::assertNotSame('', $result->getOutput());
        }
        self::assertDirectoryDoesNotExist($this->home.'/.vapor-swap');
    }

    public function testNumericProfileNamesRemainStringsAndComplete(): void
    {
        $this->profile('123');
        self::assertTrue($this->cli(['swap', '123', '--sticky'])->isSuccessful());
        $list = json_decode($this->cli(['list', '--json'])->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('123', $list[0]['name']);
        self::assertTrue($list[0]['persistent']);
        self::assertSame(['123'], json_decode($this->cli(['current', '--json'])->getOutput(), true)['profiles']);
        $completion = $this->cli(['_complete', '--shell=bash', '--input=vapor-swap', '--input=swap', '--input=', '--current=2']);
        self::assertTrue($completion->isSuccessful(), $completion->getErrorOutput());
        self::assertStringContainsString('123', $completion->getOutput());
    }

    public function testNullPersistentTokenDoesNotFallBackToEnvironment(): void
    {
        (new JsonFile($this->paths->persistent()))->update(static fn (): array => ['token' => null, 'team' => 1]);
        $result = $this->cli(['current', '--json'], ['VAPOR_API_TOKEN' => 'environment-token']);
        $current = json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('persistent', $current['source']);
        self::assertFalse($current['authenticated']);
    }
}
