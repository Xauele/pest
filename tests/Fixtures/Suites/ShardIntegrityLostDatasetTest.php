<?php

declare(strict_types=1);

namespace Tests\Fixtures\Suites;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShardIntegrityLostDatasetTest extends TestCase
{
    /** @return array<string, array{int}> */
    public static function cases(): array
    {
        $argv = $_SERVER['argv'] ?? [];
        $isDiscovery = in_array('--list-tests', $argv, true) || in_array('--list-test-ids', $argv, true);

        return $isDiscovery
            ? ['first' => [1], 'second' => [2]]
            : ['first' => [1]];
    }

    #[DataProvider('cases')]
    public function test_dataset(int $value): void
    {
        self::assertGreaterThan(0, $value);
    }
}
