<?php

declare(strict_types=1);

use Pest\Plugins\Tia\NativeShadowComparison;
use Pest\Plugins\Tia\Recorder;
use Pest\Plugins\Tia\SourceScope;
use Pest\TestSuite;

require_once __DIR__.'/NativeShadowSource.php';

it('captures actual Zend file entries alongside Xdebug coverage without changing TIA edges', function (): void {
    expect(extension_loaded('pest_observer'))->toBeTrue()
        ->and(getenv('PEST_TIA_NATIVE_SHADOW'))->toBe('1');

    $recorder = new Recorder;
    $recorder->activate();
    $recorder->beginTest('NativeShadowFakeUnloadedClass', 'test_fixture', __FILE__);

    $value = \NativeShadowFixture\Calculator::expensiveAction(4);

    $recorder->endTest();

    $source = realpath(__DIR__.'/NativeShadowSource.php');
    expect($source)->toBeString();

    $reports = $recorder->nativeShadowReports();
    expect($value)->toBe(28)
        ->and($reports)->toHaveKey(__FILE__)
        ->and($reports[__FILE__])->toHaveCount(1)
        ->and($reports[__FILE__][0]['observed_files'])->toContain($source)
        ->and($reports[__FILE__][0]['covered_files'])->toContain($source)
        ->and($reports[__FILE__][0]['safe_to_skip_tests'])->toBeFalse()
        ->and($recorder->perTestFiles()[__FILE__])->toContain($source);

    $recorder->reset();

    expect($recorder->nativeShadowReports())->toBeEmpty();
})->group('native-shadow-proof');

it('must keep unknown internal data I/O outside both file-code oracles', function (): void {
    $dataFile = tempnam(sys_get_temp_dir(), 'pest-shadow-data-');

    expect($dataFile)->toBeString();

    try {
        file_put_contents($dataFile, '{"permission":"admin"}');

        $recorder = new Recorder;
        $recorder->activate();
        $recorder->beginTest('NativeShadowFakeUnloadedClass', 'test_data_input', __FILE__);

        $contents = file_get_contents($dataFile);

        $recorder->endTest();

        $report = $recorder->nativeShadowReports()[__FILE__][0];

        expect($contents)->toBe('{"permission":"admin"}')
            ->and($report['observed_files'])->not->toContain($dataFile)
            ->and($report['safe_to_skip_tests'])->toBeFalse();
    } finally {
        @unlink($dataFile);
    }
})->group('native-shadow-proof');

it('detects deliberate oracle-only source coverage without certifying a skipped test', function (): void {
    $source = realpath(__DIR__.'/NativeShadowSource.php');
    expect($source)->toBeString();

    $evidence = [
        'scope' => 'function-entry-positive-evidence',
        'files' => [],
        'dynamic_code' => false,
        'safe_to_skip_tests' => false,
    ];

    $report = NativeShadowComparison::compare(
        $evidence, [$source], SourceScope::fromProjectRoot(TestSuite::getInstance()->rootPath),
    );

    expect($report['coverage_only'])->toBe([$source])
        ->and($report['safe_to_skip_tests'])->toBeFalse();
})->group('native-shadow-proof');

it('rejects malicious native claims of negative test-impact proof', function (): void {
    $evidence = [
        'scope' => 'function-entry-positive-evidence',
        'files' => [],
        'dynamic_code' => false,
        'safe_to_skip_tests' => true,
    ];

    expect(fn (): array => NativeShadowComparison::compare(
        $evidence, [], SourceScope::fromProjectRoot(TestSuite::getInstance()->rootPath),
    ))->toThrow(RuntimeException::class);
})->group('native-shadow-proof');

it('rejects corrupt native evidence instead of emitting an empty green report', function (): void {
    $evidence = [
        'scope' => 'function-entry-positive-evidence',
        'files' => ['valid.php', 123],
        'dynamic_code' => false,
        'safe_to_skip_tests' => false,
    ];

    expect(fn (): array => NativeShadowComparison::compare(
        $evidence, [], SourceScope::fromProjectRoot(TestSuite::getInstance()->rootPath),
    ))->toThrow(RuntimeException::class);
})->group('native-shadow-proof');

it('rejects an unavailable native shadow driver rather than silently turning off verification', function (): void {
    if (extension_loaded('pest_observer')) {
        expect(function_exists('pest_observer_start'))->toBeTrue()
            ->and(function_exists('pest_observer_stop'))->toBeTrue();

        return;
    }

    expect(function (): void {
        $recorder = new Recorder;
        $recorder->activate();
    })->toThrow(RuntimeException::class);
})->group('native-shadow-proof');
