<?php

declare(strict_types=1);

namespace Tests\Fixtures\Suites;

use PHPUnit\Framework\TestCase;

final class ShardIntegritySkippedPhpUnitTest extends TestCase
{
    public function test_it_is_intentionally_skipped(): void
    {
        self::markTestSkipped('Intentional shard integrity fixture.');
    }
}
