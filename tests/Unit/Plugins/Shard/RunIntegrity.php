<?php

use Pest\Plugins\Shard;
use Pest\Plugins\Shard\RunIntegrity;
use Symfony\Component\Console\Output\BufferedOutput;

afterEach(function (): void {
    RunIntegrity::deactivate();
});

it('records only active runs and normalises generated Pest class names', function (): void {
    RunIntegrity::observe('P\\Tests\\NotYetActive::test');
    expect(RunIntegrity::observed())->toBeEmpty();

    RunIntegrity::activate();
    RunIntegrity::observe('P\\Tests\\Feature\\Generated::test_one');
    RunIntegrity::observe('Tests\\Unit\\PhpUnitCase::test_two');
    RunIntegrity::observe('Tests\\Unit\\PhpUnitCase::test_two');

    expect(RunIntegrity::observed())->toBe([
        'Tests\\Feature\\Generated::test_one' => true,
        'Tests\\Unit\\PhpUnitCase::test_two' => true,
    ]);
});

it('detects missing and unexpected test IDs without relying on assertion counts', function (): void {
    $expected = ['Tests\\A::first' => true, 'Tests\\A::second' => true];
    $observed = ['Tests\\A::first' => true, 'Tests\\C::third' => true];

    expect(RunIntegrity::difference($expected, $observed))->toBe([
        'missing' => ['Tests\\A::second'],
        'unexpected' => ['Tests\\C::third'],
    ]);
});

it('aggregates parallel worker evidence without duplicate classes', function (): void {
    $runId = bin2hex(random_bytes(16));
    RunIntegrity::activate();
    RunIntegrity::observe('P\\Tests\\First::first');
    RunIntegrity::observe('Tests\\Second::second');
    RunIntegrity::writeWorkerEvidence($runId);

    expect(RunIntegrity::collectWorkerEvidence($runId))->toBe([
        'Tests\\First::first' => true,
        'Tests\\Second::second' => true,
    ])->and(RunIntegrity::collectWorkerEvidence($runId))->toBeNull();
});

it('fails closed on corrupted worker evidence', function (): void {
    $runId = bin2hex(random_bytes(16));
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pest-shard-integrity-'.$runId.'-999.json';
    file_put_contents($path, '{invalid');

    expect(RunIntegrity::collectWorkerEvidence($runId))->toBeNull()
        ->and(file_exists($path))->toBeFalse();
});

it('fails a green run if one assigned test ID never completed', function (): void {
    $expected = new ReflectionProperty(Shard::class, 'expectedTestIds');
    $original = $expected->getValue();

    try {
        $expected->setValue(null, ['Tests\\Seen::one' => true, 'Tests\\Seen::two' => true]);

        RunIntegrity::activate();
        RunIntegrity::observe('P\\Tests\\Seen::one');

        $output = new BufferedOutput;

        expect(new Shard($output)->addOutput(0))->toBe(1)
            ->and($output->fetch())->toContain('1 missing and 0 unexpected');
    } finally {
        $expected->setValue(null, $original);
    }
});
