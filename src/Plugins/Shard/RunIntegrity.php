<?php

declare(strict_types=1);

namespace Pest\Plugins\Shard;

use JsonException;
use RuntimeException;

/**
 * @internal
 */
final class RunIntegrity
{
    private static bool $active = false;

    /** @var array<string, true> */
    private static array $observed = [];

    /** @var array<string, true> */
    private static array $finished = [];

    /** @var array<string, true> */
    private static array $skipped = [];

    /** @var array<string, true> */
    private static array $duplicates = [];

    public static function activate(): void
    {
        self::$active = true;
        self::$observed = [];
        self::$finished = [];
        self::$skipped = [];
        self::$duplicates = [];
    }

    public static function deactivate(): void
    {
        self::$active = false;
        self::$observed = [];
        self::$finished = [];
        self::$skipped = [];
        self::$duplicates = [];
    }

    public static function observe(string $testId): void
    {
        if (! self::$active) {
            return;
        }

        $normalised = str_starts_with($testId, 'P\\') ? substr($testId, 2) : $testId;

        if ($normalised !== '') {
            self::$observed[$normalised] = true;
        }
    }

    public static function observeFinished(string $testId): void
    {
        self::observeEvent($testId, self::$finished);
    }

    public static function observeSkipped(string $testId): void
    {
        self::observeEvent($testId, self::$skipped);
    }

    /** @param array<string, true> $seen */
    private static function observeEvent(string $testId, array &$seen): void
    {
        if (! self::$active) {
            return;
        }

        $id = str_starts_with($testId, 'P\\') ? substr($testId, 2) : $testId;

        if ($id === '') {
            return;
        }

        if (isset($seen[$id])) {
            self::$duplicates[$id] = true;
        }

        $seen[$id] = true;
        self::$observed[$id] = true;
    }

    public static function hasDuplicateObservations(): bool
    {
        return self::$duplicates !== [];
    }

    /** @return array<string, true> */
    public static function observed(): array
    {
        return self::$observed;
    }

    /**
     * @param  array<string, true>  $expected
     * @param  array<string, true>  $observed
     * @return array{missing: list<string>, unexpected: list<string>}
     */
    public static function difference(array $expected, array $observed): array
    {
        return [
            'missing' => array_keys(array_diff_key($expected, $observed)),
            'unexpected' => array_keys(array_diff_key($observed, $expected)),
        ];
    }

    public static function writeWorkerEvidence(string $runId): void
    {
        $pid = getmypid();

        if ($pid === false) {
            throw new RuntimeException('Unable to identify shard worker PID.');
        }

        $path = self::filePrefix($runId).$pid.'.json';
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';

        try {
            $ids = array_keys(self::$observed);

            foreach (array_keys(self::$duplicates) as $duplicate) {
                $ids[] = $duplicate;
            }

            $contents = json_encode($ids, JSON_THROW_ON_ERROR);

            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents) || ! rename($temporary, $path)) {
                throw new RuntimeException('Unable to publish shard integrity evidence.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** @return array<string, true>|null */
    public static function collectWorkerEvidence(string $runId): ?array
    {
        $files = glob(self::filePrefix($runId).'*.json');

        if ($files === false || $files === []) {
            return null;
        }

        $observed = [];
        $valid = true;

        foreach ($files as $file) {
            $contents = @file_get_contents($file);
            @unlink($file);

            if ($contents === false) {
                $valid = false;

                continue;
            }

            try {
                $classes = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $valid = false;

                continue;
            }

            if (! is_array($classes) || ! array_is_list($classes)) {
                $valid = false;

                continue;
            }

            foreach ($classes as $class) {
                if (! is_string($class) || $class === '') {
                    $valid = false;

                    continue;
                }

                // @note Reject duplicate execution evidence across distinct workers.
                if (isset($observed[$class])) {
                    $valid = false;
                }

                $observed[$class] = true;
            }
        }

        return $valid ? $observed : null;
    }

    private static function filePrefix(string $runId): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'pest-shard-integrity-'.$runId.'-';
    }
}
