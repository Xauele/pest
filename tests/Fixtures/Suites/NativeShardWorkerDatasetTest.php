<?php

declare(strict_types=1);

namespace Tests\Fixtures\Suites;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeShardWorkerDatasetTest extends TestCase
{
    /** @return array<string, array{int}> */
    public static function cases(): array
    {
        // Parent SuiteLoader must see both rows; a worker deliberately loses one.
        return ((int) ($_SERVER['PARATEST'] ?? getenv('PARATEST') ?: 0)) === 1
            ? ['first' => [1]]
            : ['first' => [1], 'second' => [2]];
    }

    #[DataProvider('cases')]
    public function test_value(int $value): void
    {
        self::assertGreaterThan(0, $value);
    }
}
