<?php

declare(strict_types=1);

use Pest\Plugins\Tia;
use Pest\Support\Coverage;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use Tests\Fixtures\Tia\PestResult;
use Tests\Fixtures\Tia\Project;

afterEach(function (): void {
    Project::destroyAll();
});

/**
 * @param  list<string>  $arguments
 */
function makeProjectWithExclusiveCoverage(array $arguments): Project
{
    $project = Project::make('master');

    $project->write('tests/Feature/CoversCalculatorTest.php', <<<'PHP'
    <?php

    declare(strict_types=1);

    use Fixture\App\Calculator;

    test('subtracts within a feature test', function (): void {
        expect((new Calculator)->subtract(10, 5))->toBe(5);
    });
    PHP);

    $project->git()->commit('Keep add covered by a single test');

    $recorded = $project->pest('--tia', ...$arguments);
    $baseline = $project->pest('--tia', '--coverage', '--min=100', ...$arguments);

    expect($recorded->exitCode)->toBe(0, $recorded->describe())
        ->and($baseline->exitCode)->toBe(0, $baseline->describe())
        ->and($baseline->output)->toContain('Total: 100.0 %');

    return $project;
}

/**
 * @return list<string>
 */
function activeCachedTestIds(Project $project): array
{
    $bytes = file_get_contents($project->graphDir().DIRECTORY_SEPARATOR.Tia::KEY_COVERAGE_CACHE);
    expect($bytes)->toBeString();

    $serialized = gzdecode((string) $bytes);
    expect($serialized)->toBeString();

    $coverage = unserialize((string) $serialized);
    expect($coverage)->toBeInstanceOf(CodeCoverage::class);
    assert($coverage instanceof CodeCoverage);

    $data = $coverage->getData(true);
    $identifiers = $data->testIds();
    $used = [];

    foreach ($data->lineCoverage() as $lines) {
        foreach ($lines as $hits) {
            if ($hits === null) {
                continue;
            }

            foreach (array_keys($hits) as $index) {
                if (isset($identifiers[$index])) {
                    $used[$identifiers[$index]] = true;
                }
            }
        }
    }

    $ids = array_keys($used);
    sort($ids);

    return $ids;
}

function reportedCoveragePercentage(PestResult $result): float
{
    if (preg_match('/Total:\s*(\d+(?:\.\d+)?)\s*%/', $result->output, $matches) !== 1) {
        throw new RuntimeException('Missing coverage total: '.$result->describe());
    }

    return (float) $matches[1];
}

test('removing a test cannot preserve its cached coverage', function (string $deletion, array $arguments): void {
    $project = makeProjectWithExclusiveCoverage($arguments);
    $removedId = Project::testId('tests/Unit/CalculatorTest.php', 'adds two numbers');

    expect(activeCachedTestIds($project))->toContain($removedId);

    if ($deletion === 'file') {
        expect(unlink($project->path('tests/Unit/CalculatorTest.php')))->toBeTrue();
    } else {
        $project->write('tests/Unit/CalculatorTest.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        use Fixture\App\Calculator;

        test('subtracts two numbers', function (): void {
            expect((new Calculator)->subtract(3, 1))->toBe(2);
        });
        PHP);
    }

    $tia = $project->pest('--tia', '--coverage', '--min=100', ...$arguments);
    $full = $project->pest('--coverage', '--min=100', ...$arguments);
    $repeat = $project->pest('--tia', '--coverage', '--min=100', ...$arguments);

    expect($full->exitCode)->toBe(1, $full->describe())
        ->and($tia->exitCode)->toBe(1, $tia->describe())
        ->and($repeat->exitCode)->toBe(1, $repeat->describe())
        ->and($tia->output)->toContain('Code coverage below expected')
        ->and(reportedCoveragePercentage($tia))->toBe(reportedCoveragePercentage($full))
        ->and(reportedCoveragePercentage($repeat))->toBe(reportedCoveragePercentage($full))
        ->and(activeCachedTestIds($project))->not->toContain($removedId);
})->with([
    'deleted file, sequential' => ['file', []],
    'deleted file, parallel' => ['file', ['--parallel', '--processes=2']],
    'deleted method, sequential' => ['method', []],
    'deleted method, parallel' => ['method', ['--parallel', '--processes=2']],
])->skipOnWindows()->skip(! Coverage::isAvailable(), 'Code coverage driver required');

test('a re-executed test with no application hits cannot retain its old cached hits', function (array $arguments): void {
    $project = makeProjectWithExclusiveCoverage($arguments);
    $oldId = Project::testId('tests/Unit/CalculatorTest.php', 'adds two numbers');

    expect(activeCachedTestIds($project))->toContain($oldId);

    $source = file_get_contents($project->path('tests/Unit/CalculatorTest.php'));
    expect($source)->toBeString();

    $replacement = str_replace(
        'expect((new Calculator)->add(1, 2))->toBe(3);',
        'expect(true)->toBeTrue();',
        (string) $source,
    );

    expect($replacement)->not->toBe($source);
    $project->write('tests/Unit/CalculatorTest.php', $replacement);

    $full = $project->pest('--coverage', '--min=100', ...$arguments);
    $tia = $project->pest('--tia', '--coverage', '--min=100', ...$arguments);
    $repeat = $project->pest('--tia', '--coverage', '--min=100', ...$arguments);

    expect($full->exitCode)->toBe(1, $full->describe())
        ->and($tia->exitCode)->toBe(1, $tia->describe())
        ->and($repeat->exitCode)->toBe(1, $repeat->describe())
        ->and($tia->output)->toContain('Code coverage below expected')
        ->and(reportedCoveragePercentage($tia))->toBe(reportedCoveragePercentage($full))
        ->and(reportedCoveragePercentage($repeat))->toBe(reportedCoveragePercentage($full))
        ->and(activeCachedTestIds($project))->not->toContain($oldId);
})->with(Project::SEQUENTIAL_AND_PARALLEL)
    ->skipOnWindows()->skip(! Coverage::isAvailable(), 'Code coverage driver required');

test('renaming a test removes its old coverage identity', function (array $arguments): void {
    $project = makeProjectWithExclusiveCoverage($arguments);
    $oldId = Project::testId('tests/Unit/CalculatorTest.php', 'adds two numbers');
    $newId = Project::testId('tests/Unit/CalculatorTest.php', 'adds renamed numbers');

    $source = file_get_contents($project->path('tests/Unit/CalculatorTest.php'));
    expect($source)->toBeString();

    $project->write('tests/Unit/CalculatorTest.php', str_replace(
        "test('adds two numbers'",
        "test('adds renamed numbers'",
        (string) $source,
    ));

    $result = $project->pest('--tia', '--coverage', '--min=100', ...$arguments);
    $ids = activeCachedTestIds($project);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($ids)->not->toContain($oldId)
        ->and($ids)->toContain($newId);
})->with(Project::SEQUENTIAL_AND_PARALLEL)
    ->skipOnWindows()->skip(! Coverage::isAvailable(), 'Code coverage driver required');

test('unchanged dataset tests retain their cached coverage on replay', function (array $arguments): void {
    $project = Project::make('master');
    $project->write('tests/Unit/CalculatorTest.php', <<<'PHP'
    <?php

    declare(strict_types=1);

    use Fixture\App\Calculator;

    test('adds two numbers', function (int $first, int $second, int $expected): void {
        expect((new Calculator)->add($first, $second))->toBe($expected);
    })->with([
        [1, 2, 3],
        [3, 5, 8],
    ]);

    test('subtracts two numbers', function (): void {
        expect((new Calculator)->subtract(3, 1))->toBe(2);
    });
    PHP);

    $project->git()->commit('Add dataset coverage');
    $recorded = $project->pest('--tia', ...$arguments);
    $baseline = $project->pest('--tia', '--coverage', '--min=100', ...$arguments);
    $before = activeCachedTestIds($project);
    $prefix = Project::testId('tests/Unit/CalculatorTest.php', 'adds two numbers');
    $datasetIds = array_values(array_filter($before, fn (string $id): bool => str_starts_with($id, $prefix.'#')));
    $replay = $project->pest('--tia', '--coverage', '--min=100', ...$arguments);

    expect($recorded->exitCode)->toBe(0, $recorded->describe())
        ->and($baseline->exitCode)->toBe(0, $baseline->describe())
        ->and($datasetIds)->toHaveCount(2)
        ->and($replay->exitCode)->toBe(0, $replay->describe())
        ->and($replay->output)->toContain('Total: 100.0 %')
        ->and(activeCachedTestIds($project))->toEqualCanonicalizing($before);
})->with(Project::SEQUENTIAL_AND_PARALLEL)
    ->skipOnWindows()->skip(! Coverage::isAvailable(), 'Code coverage driver required');

test('removing a dataset row cannot leave phantom coverage from that case', function (array $arguments): void {
    $project = Project::make('master');

    $project->write('app/Category.php', <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace Fixture\App;

    final class Category
    {
        public function category(int $number): string
        {
            if ($number > 0) {
                return 'positive';
            }

            return 'negative';
        }
    }
    PHP);

    $project->write('tests/Unit/CategoryTest.php', <<<'PHP'
    <?php

    declare(strict_types=1);

    require_once __DIR__.'/../../app/Category.php';

    use Fixture\App\Category;

    test('classifies numbers', function (int $number, string $expected): void {
        expect((new Category)->category($number))->toBe($expected);
    })->with([
        'positive' => [1, 'positive'],
        'negative' => [-1, 'negative'],
    ]);
    PHP);

    $project->git()->commit('Record both category dataset rows');

    $recorded = $project->pest('--tia', ...$arguments);
    $baseline = $project->pest('--tia', '--coverage', '--min=100', ...$arguments);
    $ids = activeCachedTestIds($project);
    $negativeIds = array_values(array_filter(
        $ids,
        fn (string $id): bool => str_contains($id, 'CategoryTest::') && str_contains($id, 'negative'),
    ));

    expect($recorded->exitCode)->toBe(0, $recorded->describe())
        ->and($baseline->exitCode)->toBe(0, $baseline->describe())
        ->and($negativeIds)->toHaveCount(1, implode(', ', $ids));

    $missingId = $negativeIds[0];

    $source = file_get_contents($project->path('tests/Unit/CategoryTest.php'));
    expect($source)->toBeString();

    $edited = str_replace("'negative' => [-1, 'negative'],", '', (string) $source);
    expect($edited)->not->toBe($source);
    $project->write('tests/Unit/CategoryTest.php', $edited);

    $full = $project->pest('--coverage', '--min=100', ...$arguments);
    $replay = $project->pest('--tia', '--coverage', '--min=100', ...$arguments);

    expect($full->exitCode)->toBe(1, $full->describe())
        ->and($replay->exitCode)->toBe(1, $replay->describe())
        ->and(reportedCoveragePercentage($replay))->toBe(reportedCoveragePercentage($full))
        ->and(activeCachedTestIds($project))->not->toContain($missingId);
})->with(Project::SEQUENTIAL_AND_PARALLEL)
    ->skipOnWindows()->skip(! Coverage::isAvailable(), 'Code coverage driver required');
