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
        // Stream the file through the native xxh128 implementation instead of
        // allocating its contents and tokenizing PHP or parsing JS via regex.
        return @hash_file('xxh128', $absolute);
    }

    public static function ofContent(string $path, string $raw): string
    {
        // The path remains for API compatibility. The tree already keys hashes
        // by relative path; source identity must include every observable byte.
        //
        // Docblocks, __LINE__, Blade whitespace and JS pragmas may affect
        // behavior, so stripping them can certify stale cached test results.
        return hash('xxh128', $raw);
    }
}
