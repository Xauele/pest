<?php

declare(strict_types=1);

namespace NativeShardNested;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class OuterTest extends TestCase
{
    public function test_nested_php_pest_process_does_not_change_outer_attestation(): void
    {
        $process = new Process([
            PHP_BINARY,
            'bin/pest',
            '--configuration=tests/Fixtures/Suites/NativeShardNestedInner.xml',
            '--colors=never',
        ], dirname(__DIR__, 3), [
            'PAO_DISABLE' => '1',
            'COLLISION_PRINTER' => 'DefaultPrinter',
        ]);

        $process->setTimeout(30);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString('1 passed', $process->getOutput());
    }
}
