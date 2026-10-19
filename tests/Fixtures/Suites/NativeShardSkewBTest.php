<?php

declare(strict_types=1);

namespace NativeShardSkew;

use PHPUnit\Framework\TestCase;

final class NativeShardSkewBTest extends TestCase
{
    public function test_slow_method(): void
    {
        usleep(400_000);
        self::assertTrue(true);
    }
}
