<?php

declare(strict_types=1);

namespace NativeShardInherited;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class NativeShardInheritedParentTest extends TestCase
{
    #[Test]
    public function inherited_test_method(): void
    {
        self::assertTrue(true);
    }
}
