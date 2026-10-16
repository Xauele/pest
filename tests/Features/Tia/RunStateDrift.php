<?php

declare(strict_types=1);

use Pest\Plugins\Tia\ChangedFiles;
use Pest\Support\Coverage;
use Tests\Fixtures\Tia\Project;

afterEach(function (): void {
    Project::destroyAll();
});

test('a source edit during replay cannot be stamped as tested', function (array $arguments, bool $dirtyBeforeRun): void {
    $project = Project::make('master');
    $project->seed('master');

    $project->write('tests/Unit/CalculatorTest.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        use Fixture\App\Calculator;

        test('adds two numbers', function (): void {
            $source = dirname(__DIR__, 2).'/app/Greeter.php';
            $contents = (string) file_get_contents($source);

            if (! str_contains($contents, 'function changedDuringTest')) {
                $contents = str_replace(
                    '    public function greet',
                    "    public function changedDuringTest(): int { return 42; }\n\n    public function greet",
                    $contents,
                );
                file_put_contents($source, $contents);
            }

            expect((new Calculator)->add(1, 2))->toBe(3);
        });

        test('subtracts two numbers', function (): void {
            expect((new Calculator)->subtract(3, 1))->toBe(2);
        });
        PHP);

    if ($dirtyBeforeRun) {
        $source = (string) file_get_contents($project->path('app/Greeter.php'));
        $project->write('app/Greeter.php', str_replace(
            '    public function greet',
            "    public function changedBeforeTest(): int { return 1; }\n\n    public function greet",
            $source,
        ));
    }

    $previous = $project->graph();
    $first = $project->pest('--tia', ...$arguments);

    expect($first->tally())->toContain('6 passed')
        ->and($first->exitCode)->toBe(1, $first->describe())
        ->and($first->output)->toContain('Project sources changed during the run')
        ->and($project->graph())->toEqual($previous);

    $second = $project->pest('--tia', ...$arguments);

    expect($second->exitCode)->toBe(0, $second->describe())
        ->and($second->tally())->toContain('6 passed')
        ->and($second->replayed())->toBeLessThan(Project::TOTAL_TESTS, $second->describe());
})->with([
    'sequential clean' => [[], false],
    'sequential dirty' => [[], true],
    'parallel clean' => [['--parallel', '--processes=2'], false],
    'parallel dirty' => [['--parallel', '--processes=2'], true],
])->skipOnWindows();

test('a PHPDoc-only edit cannot slip past the run integrity check', function (array $arguments): void {
    $project = Project::make('master');
    $project->seed('master');

    $project->write('tests/Unit/CalculatorTest.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        use Fixture\App\Calculator;

        test('adds two numbers', function (): void {
            $source = dirname(__DIR__, 2).'/app/Greeter.php';
            $contents = (string) file_get_contents($source);

            if (! str_contains($contents, '@Route("/changed")')) {
                file_put_contents($source, str_replace(
                    '    public function greet',
                    "    /** @Route(\"/changed\") */\n    public function greet",
                    $contents,
                ));
            }

            expect((new Calculator)->add(1, 2))->toBe(3);
        });

        test('subtracts two numbers', function (): void {
            expect((new Calculator)->subtract(3, 1))->toBe(2);
        });
        PHP);

    $previous = $project->graph();
    $first = $project->pest('--tia', ...$arguments);

    expect($first->tally())->toContain('6 passed')
        ->and($first->exitCode)->toBe(1, $first->describe())
        ->and($first->output)->toContain('Project sources changed during the run')
        ->and($project->graph())->toEqual($previous);

    $second = $project->pest('--tia', ...$arguments);

    expect($second->exitCode)->toBe(0, $second->describe())
        ->and($second->tally())->toContain('6 passed');
})->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();

test('a comment-only dirty path is snapshotted without unnecessarily selecting its tests', function (): void {
    $project = Project::make('master');
    $changed = new ChangedFiles($project->path);
    $before = $changed->runSnapshot();

    $source = (string) file_get_contents($project->path('app/Greeter.php'));
    $project->write('app/Greeter.php', str_replace(
        '    public function greet',
        "    /** @Route(\"/changed\") */\n    public function greet",
        $source,
    ));

    $after = $changed->runSnapshot();

    expect($before)->not->toBeNull()
        ->and($after)->not->toBeNull();

    assert($before !== null && $after !== null);

    expect($after['sha'])->toBe($before['sha'])
        ->and($after['tree'])->toHaveKey('app/Greeter.php')
        ->and($after['raw'])->not->toBe($before['raw'])
        ->and($changed->filterUnchangedSinceLastRun(['app/Greeter.php'], $after['tree']))->toBeEmpty();
})->skipOnWindows();

test('an unchanged PHPDoc edit made before the run is not mistaken for run drift', function (array $arguments): void {
    $project = Project::make('master');
    $project->seed('master');

    $source = (string) file_get_contents($project->path('app/Greeter.php'));
    $project->write('app/Greeter.php', str_replace(
        '    public function greet',
        "    /** @Route(\"/before\") */\n    public function greet",
        $source,
    ));

    $result = $project->pest('--tia', ...$arguments);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->output)->not->toContain('Project sources changed during the run');
})->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();

test('a working tree that was dirty before the run remains valid if it stays unchanged', function (array $arguments): void {
    $project = Project::make('master');
    $project->seed('master');

    $source = (string) file_get_contents($project->path('app/Greeter.php'));
    $project->write('app/Greeter.php', str_replace(
        '    public function greet',
        "    public function alreadyDirty(): bool { return true; }\n\n    public function greet",
        $source,
    ));

    $result = $project->pest('--tia', ...$arguments);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->output)->not->toContain('Project sources changed during the run');
})->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();

test('fresh recording rejects a new source file created during execution', function (array $arguments): void {
    $project = Project::make('master');
    $project->seed('master');

    $project->write('tests/Unit/CalculatorTest.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        use Fixture\App\Calculator;

        test('adds two numbers', function (): void {
            file_put_contents(dirname(__DIR__, 2).'/app/GeneratedDuringRun.php', "<?php\n// created during test\n");
            expect((new Calculator)->add(1, 2))->toBe(3);
        });

        test('subtracts two numbers', function (): void {
            expect((new Calculator)->subtract(3, 1))->toBe(2);
        });
        PHP);

    $previous = $project->graph();
    $result = $project->pest('--tia', '--fresh', ...$arguments);
    $graphAfter = $project->graph();

    expect($result->tally())->toContain('6 passed')
        ->and($result->exitCode)->toBe(1, $result->describe())
        ->and($result->output)->toContain('Project sources changed during the run')
        ->and($graphAfter === null || $graphAfter === $previous)->toBeTrue();
})->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();

test('cleanup of a transient PHPUnit configuration cannot invalidate TIA recording', function (): void {
    $project = Project::make('master');
    $project->write('.pest.xml', '<phpunit/>');

    $result = $project->pest('--tia');

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->tally())->toContain('6 passed')
        ->and($project->graphExists())->toBeTrue()
        ->and($result->output)->not->toContain('Project sources changed during the run')
        ->and($project->path('.pest.xml'))->not->toBeFile();
})->skipOnWindows()->skip(! Coverage::isAvailable(), 'Coverage is not available');
