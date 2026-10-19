<?php

declare(strict_types=1);

namespace Vendor\Tests\Shard;

use PHPUnit\Framework\TestCase;

final class SameNameTest extends TestCase
{
    public function test_it_runs_only_when_selected(): void
    {
        self::assertTrue(true);
    }
}
