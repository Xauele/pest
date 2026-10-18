<?php

declare(strict_types=1);

namespace NativeShardSkew;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeShardSkewATest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function manyRows(): iterable
    {
        foreach (range(1, 100) as $value) {
            yield 'row-'.$value => [$value];
        }
    }

    #[DataProvider('manyRows')]
    public function test_fast_dataset_row(int $value): void
    {
        self::assertGreaterThan(0, $value);
    }
}
