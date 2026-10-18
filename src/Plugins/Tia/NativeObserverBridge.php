<?php

declare(strict_types=1);

namespace Pest\Plugins\Tia;

use RuntimeException;

/**
 * @internal
 */
final class NativeObserverBridge
{
    public static function available(): bool
    {
        return extension_loaded('pest_observer')
            && function_exists('pest_observer_start')
            && function_exists('pest_observer_stop');
    }

    public static function start(string $canonicalRoot): bool
    {
        if (! self::available()) {
            throw new RuntimeException('The native Pest observer is unavailable.');
        }

        /** @var callable(string): bool $start */
        $start = 'pest_observer_start';

        return $start($canonicalRoot);
    }

    public static function stop(): mixed
    {
        if (! self::available()) {
            throw new RuntimeException('The native Pest observer is unavailable during scope cleanup.');
        }

        /** @var callable(): mixed $stop */
        $stop = 'pest_observer_stop';

        return $stop();
    }
}
