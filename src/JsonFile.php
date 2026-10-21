<?php

declare(strict_types=1);

namespace VaporSwap;

/** Locked read/modify/write with an atomic replacement; never truncates invalid JSON. */
final class JsonFile
{
    public function __construct(public readonly string $path)
    {
    }

    public function read(): array
    {
        return $this->decode()[0];
    }

    private function decode(): array
    {
        if (!file_exists($this->path)) {
            return [[], new \stdClass()];
        }
        $contents = @file_get_contents($this->path);
        if (false === $contents) {
            throw new \RuntimeException('Cannot read '.$this->path);
        }
        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Invalid JSON in '.$this->path.'. Repair it before continuing.');
        }
        if (!is_array($data) || !str_starts_with(ltrim($contents), '{')) {
            throw new \RuntimeException('Expected a JSON object in '.$this->path);
        }

        return [$data, json_decode($contents, false, 512, JSON_THROW_ON_ERROR)];
    }

    public function update(callable $change): array
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create '.$directory);
        }
        if (is_link($this->path) || is_link($this->path.'.lock')) {
            throw new \RuntimeException('Refusing to replace a symlink: '.$this->path);
        }
        $lock = @fopen($this->path.'.lock', 'c');
        if (false === $lock) {
            throw new \RuntimeException('Cannot lock '.$this->path);
        }
        $temporary = null;
        try {
            if (!@chmod($this->path.'.lock', 0600) || !flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock '.$this->path);
            }
            [$current, $original] = $this->decode();
            $data = $change($current);
            $json = json_encode(self::preserveObjects((object) $data, $original), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            $temporary = tempnam($directory, '.vapor-swap-');
            if (false === $temporary || !@chmod($temporary, 0600)) {
                throw new \RuntimeException('Cannot create a private temporary file in '.$directory);
            }
            if (@file_put_contents($temporary, $json) !== strlen($json) || !@rename($temporary, $this->path)) {
                throw new \RuntimeException('Cannot save '.$this->path);
            }

            return $data;
        } finally {
            if (null !== $temporary && is_file($temporary)) {
                @unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Preserve JSON object/array distinctions in unrelated configuration keys. */
    private static function preserveObjects(mixed $value, mixed $original): mixed
    {
        if (!is_array($value) && !$value instanceof \stdClass) {
            return $value;
        }
        $object = $value instanceof \stdClass || $original instanceof \stdClass;
        $items = (array) $value;
        $previous = is_array($original) || $original instanceof \stdClass ? (array) $original : [];
        foreach ($items as $key => $item) {
            $items[$key] = self::preserveObjects($item, $previous[$key] ?? null);
        }

        return $object ? (object) $items : $items;
    }
}
