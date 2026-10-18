<?php

declare(strict_types=1);

namespace Pest\Plugins\Tia;

/**
 * @internal
 */
final class ContentHash
{
    public static function of(string $absolute): string|false
    {
        return @hash_file('xxh128', $absolute);
    }

    public static function ofContent(string $path, string $raw): string
    {
        return hash('xxh128', $raw);
    }
}
