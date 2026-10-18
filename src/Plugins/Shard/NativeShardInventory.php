<?php

declare(strict_types=1);

namespace Pest\Plugins\Shard;

use Pest\TestSuite;
use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Code\TestMethod;
use RuntimeException;
use SplPriorityQueue;

/**
 * @internal Native PHPUnit discovery oracle for the experimental ParaTest fast path.
 */
final class NativeShardInventory
{
    /** @var array<string, array<string, true>>|null */
    private static ?array $byFile = null;

    /**
     * @param  list<Test>  $tests
     */
    public static function capture(array $tests): void
    {
        $byFile = [];

        foreach ($tests as $test) {
            if (! $test instanceof TestMethod) {
                throw new RuntimeException(sprintf(
                    'Native parallel shards currently support PHPUnit test methods only. Unsupported test type [%s]; run PHPT tests without ParaTest.',
                    get_debug_type($test),
                ));
            }

            $file = $test->file();

            $class = $test->className();

            if (str_starts_with($class, 'P\\') && class_exists($class, false) && property_exists($class, '__filename')) {
                $original = $class::$__filename;

                if (is_string($original) && $original !== '') {
                    $file = $original;
                }
            }

            $file = self::canonicalFile($file);
            $id = $test->id();
            if (isset($byFile[$file][$id])) {
                throw new RuntimeException('Duplicate native PHPUnit test identity: '.$id);
            }

            $byFile[$file][$id] = true;
        }

        self::$byFile = $byFile;
    }

    /**
     * @param  list<non-empty-string>  $available
     * @return list<non-empty-string>
     */
    public static function filterDiscoveredFiles(array $available): array
    {
        if (self::$byFile === null) {
            throw new RuntimeException('Native parallel sharding requires a PHPUnit test inventory. This suite may contain unsupported PHPT tests; retry without PEST_SHARD_NATIVE=1.');
        }

        $files = [];

        foreach ($available as $path) {
            $canonical = self::canonicalFile($path);

            if (isset(self::$byFile[$canonical])) {
                $files[$canonical] = $canonical;
            }
        }

        foreach (array_keys(self::$byFile) as $discovered) {
            if (! isset($files[$discovered])) {
                throw new RuntimeException('A discovered native PHPUnit test file was not available to ParaTest: '.$discovered);
            }
        }

        return array_values($files);
    }

    /**
     * @param  list<non-empty-string>  $available
     * @return list<non-empty-string>
     */
    public static function selectFiles(array $available, int $index, int $total): array
    {
        if (self::$byFile === null) {
            throw new RuntimeException('Native PHPUnit test inventory is missing.');
        }

        /** @var array<non-empty-string, int> $weights */
        $weights = [];

        foreach ($available as $filename) {
            $canonical = self::canonicalFile($filename);

            if (! isset(self::$byFile[$canonical])) {
                throw new RuntimeException('Native shard cannot allocate an undiscovered file: '.$canonical);
            }

            $weights[$canonical] = count(self::$byFile[$canonical]);
        }

        return self::allocateByTestCount($weights, $index, $total);
    }

    /**
     * @param  list<non-empty-string>  $available
     * @return list<non-empty-string>
     */
    public static function allocateInDiscoveryOrder(array $available, int $index, int $total): array
    {
        if ($total < 1 || $index < 1 || $index > $total) {
            throw new RuntimeException('Invalid native shard index or total.');
        }

        $selected = [];

        foreach ($available as $position => $filename) {
            if ($position % $total === $index - 1) {
                $selected[] = $filename;
            }
        }

        return $selected;
    }

    /**
     * @param  array<non-empty-string, int>  $testCounts
     * @return list<non-empty-string>
     */
    public static function allocateByTestCount(array $testCounts, int $index, int $total): array
    {
        if ($total < 1 || $index < 1 || $index > $total) {
            throw new RuntimeException('Invalid native shard index or total.');
        }

        foreach ($testCounts as $count) {
            if ($count < 1) {
                throw new RuntimeException('Native shard weights require at least one discovered test per file.');
            }
        }

        $files = array_keys($testCounts);
        usort($files, static fn (string $left, string $right): int => ($testCounts[$right] <=> $testCounts[$left]) ?: strcmp($left, $right));

        $activeShards = min($total, count($files));

        if ($index > $activeShards) {
            return [];
        }

        /** @var SplPriorityQueue<array{int, int}, int> $queue */
        $queue = new SplPriorityQueue;
        $queue->setExtractFlags(SplPriorityQueue::EXTR_DATA);

        /** @var array<int, int> $loads */
        $loads = [];

        for ($shard = 1; $shard <= $activeShards; $shard++) {
            $loads[$shard] = 0;
            $queue->insert($shard, [0, -$shard]);
        }

        $selected = [];

        foreach ($files as $filename) {
            $shard = $queue->extract();

            if (! is_int($shard)) {
                throw new RuntimeException('Native shard heap returned an invalid index.');
            }

            if ($shard === $index) {
                $selected[] = $filename;
            }

            $loads[$shard] += $testCounts[$filename];
            $queue->insert($shard, [-$loads[$shard], -$shard]);
        }

        return $selected;
    }

    public static function release(): void
    {
        self::$byFile = null;
    }

    /**
     * @param  list<non-empty-string>  $selected
     * @return array<string, true>
     */
    public static function selectedIds(array $selected): array
    {
        if (self::$byFile === null) {
            throw new RuntimeException('Native shard discovery did not publish its PHPUnit test inventory.');
        }

        $ids = [];

        foreach ($selected as $filename) {
            $file = self::canonicalFile($filename);

            if (! isset(self::$byFile[$file])) {
                throw new RuntimeException('Native shard cannot attest an undiscovered file: '.$file);
            }

            foreach (self::$byFile[$file] as $id => $present) {
                if (isset($ids[$id])) {
                    throw new RuntimeException('Duplicate test identity across native shard files: '.$id);
                }

                $ids[$id] = $present;
            }
        }

        return $ids;
    }

    /**
     * @param  non-empty-string  $path
     * @return non-empty-string
     */
    private static function canonicalFile(string $path): string
    {
        return realpath($path)
            ?: realpath(TestSuite::getInstance()->rootPath.DIRECTORY_SEPARATOR.$path)
            ?: $path;
    }
}
