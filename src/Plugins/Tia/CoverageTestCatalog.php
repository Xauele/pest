<?php

declare(strict_types=1);

namespace Pest\Plugins\Tia;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\TestSuite\Loaded;

/**
 * @internal
 */
final class CoverageTestCatalog
{
    /** @var array<string, string> */
    private array $files = [];

    private bool $loaded = false;

    public function capture(Loaded $event): void
    {
        $files = [];

        foreach ($event->testSuite()->tests()->asArray() as $test) {
            if ($test instanceof TestMethod) {
                $file = $test->file();
                $class = $test->className();

                if (str_starts_with($class, 'P\\') && class_exists($class, false) && property_exists($class, '__filename')) {
                    $nativeFile = $class::$__filename;

                    if (is_string($nativeFile) && $nativeFile !== '') {
                        $file = $nativeFile;
                    }
                }

                $files[$test->id()] = $file;
            }
        }

        $this->files = $files;
        $this->loaded = true;
    }

    public function hasLoadedSuite(): bool
    {
        return $this->loaded;
    }

    public function fileFor(string $id): ?string
    {
        return $this->files[$id] ?? null;
    }
}
