<?php

declare(strict_types=1);

namespace Pest\Plugins\Shard;

use Pest\TestSuite;
use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Code\TestMethod;
use RuntimeException;

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
            $file = $test->file();

            if ($test instanceof TestMethod) {
                $class = $test->className();

                if (str_starts_with($class, 'P\\') && class_exists($class, false) && property_exists($class, '__filename')) {
                    $original = $class::$__filename;

                    if (is_string($original) && $original !== '') {
                        $file = $original;
                    }
                }
            }

            $file = self::canonicalFile($file);
            $id = $test->id();
            $id = str_starts_with($id, 'P\\') ? substr($id, 2) : $id;
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
            throw new RuntimeException('Native PHPUnit discovery inventory is missing.');
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
