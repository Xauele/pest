<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('uses the native ParaTest suite inventory for Pest and PHPUnit sharding', function (string $configuration, string $expected): void {
    foreach (['1/2', '2/2'] as $shard) {
        $command = [
            PHP_BINARY,
            'bin/pest',
            '--configuration=tests/Fixtures/Suites/'.$configuration,
            '--parallel',
            '--processes=2',
            '--shard='.$shard,
        ];

        $process = new Process(
            $command,
            dirname(__DIR__, 2),
            [
                'PEST_SHARD_NATIVE' => '1',
                'PEST_NATIVE_SHARD_DEBUG' => '1',
                'COLLISION_PRINTER' => 'DefaultPrinter',
                'COLLISION_IGNORE_DURATION' => 'true',
                'PAO_DISABLE' => '1',
            ],
        );
        $process->setTimeout(90);
        $process->run();

        $output = removeAnsiEscapeSequences($process->getOutput().$process->getErrorOutput());

        $expectedCount = $configuration === 'ShardIntegrity.xml' && $shard === '1/2' ? 2 : 1;

        expect($process->getExitCode())->toBe(0, $output)
            ->and($output)->toContain($expectedCount.' passed')
            ->and($output)->toContain('Shard:')
            ->and($output)->toContain($shard === '1/2' ? '1 of 2' : '2 of 2');
    }
})->with([
    ['ShardIntegrity.xml', '1 passed'],
    ['ShardIntegrityPest.xml', '1 passed'],
])->group('integration');

it('fails closed when a ParaTest worker collects fewer PHPUnit dataset rows than the parent', function (): void {
    $process = new Process(
        [
            PHP_BINARY,
            'bin/pest',
            '--configuration=tests/Fixtures/Suites/NativeShardWorkerDataset.xml',
            '--parallel',
            '--processes=2',
            '--shard=1/2',
        ],
        dirname(__DIR__, 2),
        [
            'PEST_SHARD_NATIVE' => '1',
            'COLLISION_PRINTER' => 'DefaultPrinter',
            'COLLISION_IGNORE_DURATION' => 'true',
            'PAO_DISABLE' => '1',
        ],
    );
    $process->setTimeout(90);
    $process->run();
    $output = removeAnsiEscapeSequences($process->getOutput().$process->getErrorOutput());

    expect($process->getExitCode())->not->toBe(0, $output)
        ->and($output)->toContain('Shard integrity failed: 1 missing and 0 unexpected test IDs');
})->group('integration');


it('attests native PHPT and PHPUnit tests across parallel shards', function (): void {
    foreach (['1/2', '2/2'] as $shard) {
        $process = new Process(
            [
                PHP_BINARY,
                'bin/pest',
                '--configuration=tests/Fixtures/Suites/NativeShardPhpt.xml',
                '--parallel',
                '--processes=2',
                '--shard='.$shard,
            ],
            dirname(__DIR__, 2),
            [
                'PEST_SHARD_NATIVE' => '1',
                'COLLISION_PRINTER' => 'DefaultPrinter',
                'COLLISION_IGNORE_DURATION' => 'true',
                'PAO_DISABLE' => '1',
            ],
        );
        $process->setTimeout(90);
        $process->run();
        $output = removeAnsiEscapeSequences($process->getOutput().$process->getErrorOutput());

        expect($process->getExitCode())->toBe(0, $output)
            ->and($output)->toContain('1 passed')
            ->and($output)->not->toContain('Shard integrity failed');
    }
})->group('integration');
