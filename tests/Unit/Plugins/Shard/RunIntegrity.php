<?php

use Pest\Plugins\Shard;
use Pest\Plugins\Shard\RunIntegrity;
use Symfony\Component\Console\Output\BufferedOutput;

afterEach(function (): void {
    RunIntegrity::deactivate();
});

it('records only active runs and normalises generated Pest class names', function (): void {
    RunIntegrity::observe('P\\Tests\\NotYetActive');
    expect(RunIntegrity::observed())->toBeEmpty();

    RunIntegrity::activate();
    RunIntegrity::observe('P\\Tests\\Feature\\Generated');
    RunIntegrity::observe('Tests\\Unit\\PhpUnitCase');
    RunIntegrity::observe('Tests\\Unit\\PhpUnitCase');

    expect(RunIntegrity::observed())->toBe([
        'Tests\\Feature\\Generated' => true,
        'Tests\\Unit\\PhpUnitCase' => true,
    ]);
});

it('does not mistake an observed class for proof that every assigned class completed', function (): void {
    $expected = ['Tests\\A' => true, 'Tests\\B' => true];
    $observed = ['Tests\\A' => true];

    expect(RunIntegrity::difference($expected, $observed))->toBe([
        'missing' => ['Tests\\B'],
        'unexpected' => [],
    ]);
});

it('detects missing and unexpected classes without relying on assertion counts', function (): void {
    $expected = ['Tests\\A' => true, 'Tests\\B' => true];
    $observed = ['Tests\\A' => true, 'Tests\\C' => true];

    expect(RunIntegrity::difference($expected, $observed))->toBe([
        'missing' => ['Tests\\B'],
        'unexpected' => ['Tests\\C'],
    ]);
});

it('aggregates parallel worker evidence without duplicate classes', function (): void {
    $runId = bin2hex(random_bytes(16));
    RunIntegrity::activate();
    RunIntegrity::observe('P\\Tests\\First');
    RunIntegrity::observe('Tests\\Second');
    RunIntegrity::writeWorkerEvidence($runId);

    expect(RunIntegrity::collectWorkerEvidence($runId))->toBe([
        'Tests\\First' => true,
        'Tests\\Second' => true,
    ])->and(RunIntegrity::collectWorkerEvidence($runId))->toBeNull();
});

it('fails closed on corrupted worker evidence', function (): void {
    $runId = bin2hex(random_bytes(16));
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pest-shard-integrity-'.$runId.'-999.json';
    file_put_contents($path, '{invalid');

    expect(RunIntegrity::collectWorkerEvidence($runId))->toBeNull()
        ->and(file_exists($path))->toBeFalse();
});

it('fails a green run if an assigned class never executed', function (): void {
    $expected = new ReflectionProperty(Shard::class, 'expectedClasses');
    $original = $expected->getValue();

    try {
        $expected->setValue(null, ['Tests\\Seen' => true, 'Tests\\Missing' => true]);

        RunIntegrity::activate();
        RunIntegrity::observe('P\\Tests\\Seen');

        $output = new BufferedOutput;

        expect(new Shard($output)->addOutput(0))->toBe(1)
            ->and($output->fetch())->toContain('1 missing and 0 unexpected');
    } finally {
        $expected->setValue(null, $original);
    }
});
