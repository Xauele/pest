<?php

declare(strict_types=1);

use Pest\Plugins\Tia\Recorder;

require_once __DIR__.'/NativeShadowSecondarySource.php';

it('captures an independent Zend observation in a second ParaTest worker', function (): void {
    $recorder = new Recorder;
    $recorder->activate();
    $recorder->beginTest('SecondShadowFakeUnloadedClass', 'calculate', __FILE__);

    $value = \NativeShadowSecondaryFixture\Mathematics::calculate();

    $recorder->endTest();

    $source = realpath(__DIR__.'/NativeShadowSecondarySource.php');
    $unrelated = realpath(__DIR__.'/NativeShadowSource.php');

    expect($source)->toBeString()
        ->and($value)->toBe(42)
        ->and($recorder->nativeShadowReports()[__FILE__][0]['observed_files'])->toContain($source)
        ->and($recorder->nativeShadowReports()[__FILE__][0]['observed_files'])->not->toContain($unrelated)
        ->and($recorder->nativeShadowReports()[__FILE__][0]['covered_files'])->toContain($source)
        ->and($recorder->nativeShadowReports()[__FILE__][0]['safe_to_skip_tests'])->toBeFalse();

    $proofDir = getenv('PEST_NATIVE_SHADOW_PID_DIR');

    if (is_string($proofDir) && $proofDir !== '') {
        file_put_contents($proofDir.DIRECTORY_SEPARATOR.'secondary.pid', (string) getmypid());
    }
})->group('native-shadow-proof');
