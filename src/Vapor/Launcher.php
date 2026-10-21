<?php

declare(strict_types=1);

namespace VaporSwap\Vapor;

/** Starts the real Vapor executable without loading vapor-swap's vendor tree. */
final class Launcher
{
    public static function run(array $arguments): int
    {
        try {
            if (getenv('VAPOR_SWAP_SESSION')) {
                require_once __DIR__.'/../JsonFile.php';
                require_once __DIR__.'/SessionConfig.php';
                $config = SessionConfig::load();
                if (empty($config['token']) || empty($config['team'])) {
                    throw new \RuntimeException('Session authentication is incomplete. Run vapor-swap swap <name> again.');
                }
                if (class_exists('Laravel\\VaporCli\\Config', false)) {
                    throw new \RuntimeException('Vapor config was loaded before the session adapter. Start Vapor through the shell integration.');
                }
                class_alias(SessionConfig::class, 'Laravel\\VaporCli\\Config');
            }
            $binary = self::binary();
            $header = file_get_contents($binary, false, null, 0, 256);
            if (false === $header || !str_contains($header, '<?php')) {
                throw new \RuntimeException('Session switching needs the PHP Vapor executable. Set VAPOR_SWAP_VAPOR_BINARY to its absolute path.');
            }
        } catch (\Throwable $error) {
            fwrite(STDERR, $error->getMessage()."\n");

            return 1;
        }
        $argv = array_merge([$binary], $arguments);
        $argc = count($argv);
        $_SERVER['argv'] = $argv;
        $_SERVER['argc'] = $argc;
        $GLOBALS['argv'] = $argv;
        $GLOBALS['argc'] = $argc;
        // Vapor's executable loads its own Composer autoloader and exits with its status.
        require $binary;

        return 0;
    }

    private static function binary(): string
    {
        $override = getenv('VAPOR_SWAP_VAPOR_BINARY');
        if ($override) {
            if (!is_file($override) || !is_readable($override)) {
                throw new \RuntimeException('VAPOR_SWAP_VAPOR_BINARY must point to a readable PHP Vapor executable.');
            }

            return $override;
        }
        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
            $candidate = ('' === $directory ? '.' : $directory).DIRECTORY_SEPARATOR.'vapor';
            if (is_file($candidate) && is_executable($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }
        throw new \RuntimeException('Cannot find Vapor on PATH. Install laravel/vapor-cli or set VAPOR_SWAP_VAPOR_BINARY.');
    }
}
