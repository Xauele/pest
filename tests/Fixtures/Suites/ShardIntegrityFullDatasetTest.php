<?php

declare(strict_types=1);

namespace Tests\Fixtures\Suites;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShardIntegrityFullDatasetTest extends TestCase
{
    /** @return array<string, array{int}> */
    public static function cases(): array
    {
        return ['first' => [1], 'second' => [2]];
    }

    #[DataProvider('cases')]
    public function test_value(int $value): void
    {
        self::assertGreaterThan(0, $value);
    }
}
