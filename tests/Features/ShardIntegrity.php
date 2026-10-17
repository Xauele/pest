<?php

use Symfony\Component\Process\Process;

function runShardIntegrityFixture(string $configuration, string $shard, bool $parallel = false, bool $separateProcessesArgument = false): array
{
    $command = [
        PHP_BINARY,
        'bin/pest',
        '--configuration=tests/Fixtures/Suites/'.$configuration,
        '--shard='.$shard,
    ];

    if ($parallel) {
        $command = [...$command, '--parallel', ...($separateProcessesArgument ? ['--processes', '2'] : ['--processes=2'])];
    }

    $process = new Process(
        $command,
        dirname(__DIR__, 2),
        ['COLLISION_PRINTER' => 'DefaultPrinter', 'COLLISION_IGNORE_DURATION' => 'true', 'PAO_DISABLE' => '1'],
    );
    $process->run();

    return [
        $process->getExitCode(),
        removeAnsiEscapeSequences($process->getOutput().$process->getErrorOutput()),
    ];
}

it('does not execute the whole suite when a shard has no assigned classes', function (bool $parallel): void {
    [$exit, $output] = runShardIntegrityFixture('ShardIntegrity.xml', '4/4', $parallel);

    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('0 files ran')
        ->and($output)->not->toContain('3 passed');
})->with([false, true])->group('integration');

it('accounts for every class across non-parallel shards, including PHPUnit classes', function (): void {
    foreach (['1/3', '2/3', '3/3'] as $shard) {
        [$exit, $output] = runShardIntegrityFixture('ShardIntegrity.xml', $shard);

        expect($exit)->toBe(0, $output)
            ->and($output)->toContain('1 file ran, out of 3')
            ->and($output)->toContain('1 passed');
    }
})->group('integration');

it('accounts for all selected Pest classes in parallel shards', function (): void {
    foreach (['1/2', '2/2'] as $shard) {
        [$exit, $output] = runShardIntegrityFixture('ShardIntegrityPest.xml', $shard, true);

        expect($exit)->toBe(0, $output)
            ->and($output)->toContain('1 file ran, out of 2')
            ->and($output)->toContain('1 passed');
    }
})->group('integration');

it('cannot silently certify a lost PHPUnit class with parallel sharding', function (): void {
    [$exit, $output] = runShardIntegrityFixture('ShardIntegrityPhpUnit.xml', '1/1', true);

    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('1 passed')
        ->and($output)->not->toContain('Shard integrity failed');
})->group('integration');

it('dispatches every class in mixed Pest and PHPUnit suites across parallel shards', function (): void {
    foreach (['1/3', '2/3', '3/3'] as $shard) {
        [$exit, $output] = runShardIntegrityFixture('ShardIntegrity.xml', $shard, true);

        expect($exit)->toBe(0, $output)
            ->and($output)->toContain('1 file ran, out of 3')
            ->and($output)->toContain('1 passed');
    }
})->group('integration');

it('accepts space-separated parallel worker counts during discovery', function (): void {
    [$exit, $output] = runShardIntegrityFixture('ShardIntegrityPest.xml', '1/2', true, true);

    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('1 file ran, out of 2')
        ->and($output)->toContain('1 passed');
})->group('integration');

it('attests intentionally skipped PHPUnit classes in serial and parallel mode', function (bool $parallel): void {
    [$exit, $output] = runShardIntegrityFixture('ShardIntegritySkippedPhpUnit.xml', '1/1', $parallel);

    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('1 skipped')
        ->and($output)->not->toContain('Shard integrity failed');
})->with([false, true])->group('integration');

it('dispatches Unicode PHPUnit classes in serial and parallel shards', function (bool $parallel): void {
    [$exit, $output] = runShardIntegrityFixture('ShardIntegrityUnicodePhpUnit.xml', '1/1', $parallel);

    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('1 passed')
        ->and($output)->not->toContain('Shard integrity failed');
})->with([false, true])->group('integration');

it('does not run classes from a different parent namespace across serial and parallel shards', function (bool $parallel): void {
    foreach (['1/2', '2/2'] as $shard) {
        [$exit, $output] = runShardIntegrityFixture('ShardIntegritySuffix.xml', $shard, $parallel);

        expect($exit)->toBe(0, $output)
            ->and($output)->toContain('1 file ran, out of 2')
            ->and($output)->toContain('1 passed')
            ->and($output)->not->toContain('Shard integrity failed');
    }
})->with([false, true])->group('integration');

it('rejects a missing dataset row in a green PHPUnit class, serial and parallel', function (bool $parallel): void {
    [$exit, $output] = runShardIntegrityFixture('ShardIntegrityLostDataset.xml', '1/1', $parallel);

    expect($exit)->toBe(1, $output)
        ->and($output)->toContain('Shard integrity failed: 1 missing and 0 unexpected test IDs');
})->with([false, true])->group('integration');

it('attests every PHPUnit dataset row, not merely its class', function (bool $parallel): void {
    [$exit, $output] = runShardIntegrityFixture('ShardIntegrityFullDataset.xml', '1/1', $parallel);

    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('2 passed')
        ->and($output)->not->toContain('Shard integrity failed');
})->with([false, true])->group('integration');

it('attests every generated Pest dataset row, not merely its class', function (bool $parallel): void {
    [$exit, $output] = runShardIntegrityFixture('ShardIntegrityPestFullDataset.xml', '1/1', $parallel);

    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('2 passed')
        ->and($output)->not->toContain('Shard integrity failed');
})->with([false, true])->group('integration');

it('rejects a missing Pest dataset row after discovery', function (bool $parallel): void {
    [$exit, $output] = runShardIntegrityFixture('ShardIntegrityPestLostDataset.xml', '1/1', $parallel);

    expect($exit)->toBe(1, $output)
        ->and($output)->toContain('Shard integrity failed: 1 missing and 0 unexpected test IDs');
})->with([false, true])->group('integration');
