<?php

declare(strict_types=1);

namespace NativeShadowFixture;

final class Calculator
{
    public static function expensiveAction(int $number): int
    {
        $first = $number + 10;
        $second = $first * 2;

        return $second;
    }
}
