<?php

declare(strict_types=1);

namespace VaporSwap\Tests;

use VaporSwap\JsonFile;
use VaporSwap\ProfileStore;

final class StorageTest extends TempHomeTestCase
{
    public function testProfilesRoundTripAndRemoveLastProfileAsObject(): void
    {
        $this->profile();
        self::assertSame(50346, $this->store->get('client')['team']);
        $this->store->remove('client');
        self::assertSame([], $this->store->all());
        self::assertStringContainsString('"profiles": {}', file_get_contents($this->paths->profiles()));
    }

    public function testDuplicateDoesNotReplaceCredentials(): void
    {
        $this->profile();
        try {
            $this->profile('client', 'different-token');
            self::fail('Duplicate must fail');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('--replace', $error->getMessage());
        }
        self::assertSame('test-client-token', $this->store->get('client')['token']);
    }

    public function testMalformedJsonIsNeverOverwritten(): void
    {
        mkdir(dirname($this->paths->profiles()), 0700);
        file_put_contents($this->paths->profiles(), '{broken');
        try {
            $this->profile();
            self::fail('Invalid JSON must fail');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Invalid JSON', $error->getMessage());
        }
        self::assertSame('{broken', file_get_contents($this->paths->profiles()));
    }

    public function testUnknownKeysSurviveStickySwitchAndFilesArePrivate(): void
    {
        $this->profile();
        (new JsonFile($this->paths->persistent()))->update(static fn (): array => ['token' => 'old', 'team' => 1, 'future_setting' => ['x' => true]]);
        $process = $this->cli(['swap', 'client', '--sticky']);
        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $config = (new JsonFile($this->paths->persistent()))->read();
        self::assertSame(['x' => true], $config['future_setting']);
        self::assertSame('test-client-token', $config['token']);
        self::assertSame(50346, $config['team']);
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0600, fileperms($this->paths->profiles()) & 0777);
            self::assertSame(0600, fileperms($this->paths->persistent()) & 0777);
            self::assertSame(0700, fileperms(dirname($this->paths->profiles())) & 0777);
        }
    }

    public function testFutureSchemaIsRejectedWithoutChanges(): void
    {
        $file = new JsonFile($this->paths->profiles());
        $file->update(static fn (): array => ['version' => 2, 'profiles' => new \stdClass()]);
        $before = file_get_contents($file->path);
        self::assertFalse($this->cli(['remove', 'client'])->isSuccessful());
        self::assertSame($before, file_get_contents($file->path));
    }

    public function testConcurrentWritersDoNotLoseProfiles(): void
    {
        $script = <<<'PHP'
require 'vendor/autoload.php';
$store = new VaporSwap\ProfileStore(new VaporSwap\JsonFile(getenv('HOME').'/.vapor-swap/config.json'));
$store->save($argv[1], ['token'=>'test-token', 'team'=>1, 'team_name'=>'Test', 'account_email'=>'test@example.test'], false);
PHP;
        $writers = [];
        for ($i = 0; $i < 8; ++$i) {
            $writer = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $script, 'profile-'.$i], dirname(__DIR__), ['HOME' => $this->home]);
            $writer->start();
            $writers[] = $writer;
        }
        foreach ($writers as $writer) {
            self::assertSame(0, $writer->wait(), $writer->getErrorOutput());
        }
        self::assertCount(8, $this->store->all());
    }

    public function testRejectsUnsafeProfileNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProfileStore::validateName('../escape');
    }

    public function testStickyPreservesEmptyObjectsAndArraysInUnrelatedSettings(): void
    {
        $this->profile();
        mkdir(dirname($this->paths->persistent()), 0700);
        file_put_contents($this->paths->persistent(), '{"token":"old","team":1,"empty_object":{},"empty_array":[],"nested":{"0":{"empty":{}}}}');
        self::assertTrue($this->cli(['swap', 'client', '--sticky'])->isSuccessful());
        $config = json_decode(file_get_contents($this->paths->persistent()));
        self::assertInstanceOf(\stdClass::class, $config->empty_object);
        self::assertSame([], $config->empty_array);
        self::assertInstanceOf(\stdClass::class, $config->nested->{'0'}->empty);
    }
}
