<?php

declare(strict_types=1);

namespace NativeShardNested;

use PHPUnit\Framework\TestCase;

final class InnerTest extends TestCase
{
    public function test_inner_sentinel(): void
    {
        self::assertTrue(true);
    }
}
