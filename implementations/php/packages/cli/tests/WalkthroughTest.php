<?php

declare(strict_types=1);

namespace Summae\Cli\Tests;

use PHPUnit\Framework\TestCase;
use Summae\Cli\Cli;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Walkthrough scenarios — the gated form of the user documentation
 * (`docs/handbuch/cli-walkthrough.md`). One scenario per configuration we ship
 * (de / us / default / free `rules.json`), each a complete lifecycle: workspace →
 * postings → settlement → reversal → reports → close → export.
 *
 * Reads the SAME `scenarios/` files as the Node
 * `walkthrough.test.ts` and pins the same expectations — the shared-oracle
 * mechanism applied to the CLI surface. What this covers that the conformance
 * suite cannot: the CLI itself, the workspace, the pack library, and the
 * documented parameter names.
 */
final class WalkthroughTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../../../../..';
    /** The documentation in executable form — one per shipped configuration. */
    private const SCENARIO_DIR = self::REPO_ROOT . '/testing/scenarios/walkthrough';
    /** Fixed defects, pinned so they cannot come back — adversarial input lives here only. */
    private const REGRESSION_DIR = self::REPO_ROOT . '/testing/scenarios/regression';

    /**
     * @return array<string, array{string}>
     */
    public static function scenarioProvider(): array
    {
        $cases = [];
        foreach (self::scenarioFiles() as $file) {
            $cases[basename($file, '.json')] = [$file];
        }

        return $cases;
    }

    /**
     * @return list<string>
     */
    private static function scenarioFiles(): array
    {
        return [...self::filesIn(self::SCENARIO_DIR), ...self::filesIn(self::REGRESSION_DIR)];
    }

    /**
     * Documentation scenarios only — the pack-coverage guard asks what we SHIP, and a
     * regression scenario is not an offer to a user.
     *
     * @return list<string>
     */
    private static function documentedScenarioFiles(): array
    {
        return self::filesIn(self::SCENARIO_DIR);
    }

    /** @return list<string> */
    private static function filesIn(string $directory): array
    {
        $files = glob($directory . '/*.json');
        $files = is_array($files) ? $files : [];
        $files = array_values(array_filter(
            $files,
            static fn (string $file): bool => !str_ends_with($file, '-rules.json'),
        ));
        sort($files);

        return $files;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scenarioProvider')]
    public function testScenario(string $file): void
    {
        /** @var array<string, mixed> $scenario */
        $scenario = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $id = is_string($scenario['id'] ?? null) ? $scenario['id'] : basename($file, '.json');

        $dir = sys_get_temp_dir() . '/summae-walkthrough-' . $id . '-' . bin2hex(random_bytes(4));
        mkdir($dir);

        /** @var array<string, mixed> $init */
        $init = is_array($scenario['init'] ?? null) ? $scenario['init'] : [];
        $args = ['command' => 'init', '--name' => 'Walkthrough ' . $id, '--dir' => $dir];
        if (is_string($init['pack'] ?? null)) {
            $args['--pack'] = $init['pack'];
        }
        if (is_string($init['rules'] ?? null)) {
            $args['--rules'] = self::SCENARIO_DIR . '/' . $init['rules'];
        }
        if (is_string($init['currency'] ?? null)) {
            $args['--currency'] = $init['currency'];
        }
        if (is_int($init['firstFiscalYear'] ?? null)) {
            $args['--first-fiscal-year'] = (string) $init['firstFiscalYear'];
        }

        $result = $this->runCli($args);
        self::assertSame(0, $result['exit'], 'init: ' . $result['raw']);
        foreach ((is_array($scenario['expect'] ?? null) ? $scenario['expect'] : []) as $path => $want) {
            self::assertSame($want, $this->at($result['json'], (string) $path), "[$id] init → $path");
        }

        $captured = [];
        foreach ((is_array($scenario['steps'] ?? null) ? $scenario['steps'] : []) as $rawStep) {
            if (!is_array($rawStep)) {
                continue;
            }
            /** @var array<string, mixed> $step */
            $step = $rawStep;
            $repeat = is_array($step['repeat'] ?? null) ? $step['repeat'] : null;
            $values = $repeat !== null && is_array($repeat['values'] ?? null) ? $repeat['values'] : [null];

            foreach ($values as $value) {
                $scope = $captured;
                if ($repeat !== null && is_string($repeat['over'] ?? null)) {
                    $scope[$repeat['over']] = $value;
                }
                $captured = $this->runStep($id, $step, $scope, $captured, $dir);
            }
        }
    }

    /**
     * @param array<string, mixed>  $step
     * @param array<string, mixed>  $scope
     * @param array<string, mixed>  $captured
     * @return array<string, mixed>
     */
    private function runStep(string $id, array $step, array $scope, array $captured, string $dir): array
    {
        $name = is_string($step['name'] ?? null) ? $step['name'] : '(unnamed)';
        $where = "[$id] $name";

        $payload = json_encode(
            $this->substitute($step['input'] ?? $step['params'] ?? new \stdClass(), $scope, $where),
            JSON_THROW_ON_ERROR,
        );

        $result = is_string($step['op'] ?? null)
            ? $this->runCli(['command' => 'op', 'operation' => $step['op'], '--input' => $payload, '--dir' => $dir])
            : $this->runCli([
                'command' => 'report',
                'projection' => is_string($step['report'] ?? null) ? $step['report'] : '',
                '--params' => $payload,
                '--dir' => $dir,
            ]);

        if (is_string($step['expectError'] ?? null)) {
            self::assertSame($step['expectError'], $result['json']['error'] ?? null, "$where → error code");
            if (is_int($step['expectExitCode'] ?? null)) {
                self::assertSame($step['expectExitCode'], $result['exit'], "$where → exit code");
            }

            return $captured;
        }

        self::assertArrayNotHasKey('error', $result['json'], "$where → unexpected error: " . $result['raw']);
        self::assertSame(0, $result['exit'], "$where → exit code");

        foreach ((is_array($step['capture'] ?? null) ? $step['capture'] : []) as $key => $path) {
            $captured[(string) $key] = $this->at($result['json'], is_string($path) ? $path : '');
        }
        foreach ((is_array($step['expect'] ?? null) ? $step['expect'] : []) as $path => $want) {
            self::assertSame(
                $this->substitute($want, $scope, $where),
                $this->at($result['json'], (string) $path),
                "$where → $path",
            );
        }

        return $captured;
    }

    /** `openItemsCreated[0].id`, `keys.81.tax` — an unresolvable path fails, it does not skip. */
    private function at(mixed $value, string $path): mixed
    {
        $current = $value;
        foreach (explode('.', (string) preg_replace('/\[(\d+)\]/', '.$1', $path)) as $key) {
            self::assertIsArray($current, "path \"$path\" runs out at \"$key\"");
            $current = $current[$key] ?? null;
        }

        return $current;
    }

    /**
     * Replaces every `"$name"` with the captured value of that name (deeply).
     *
     * @param array<string, mixed> $scope
     */
    private function substitute(mixed $value, array $scope, string $where): mixed
    {
        if (is_string($value) && str_starts_with($value, '$')) {
            $name = substr($value, 1);
            self::assertArrayHasKey($name, $scope, "$where refers to \"\$$name\", which nothing captured");

            return $scope[$name];
        }
        if ($value instanceof \stdClass) {
            return $value;
        }
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->substitute($item, $scope, $where), $value);
        }

        return $value;
    }

    public function testEveryShippedPackHasAScenario(): void
    {
        $dirs = glob(self::REPO_ROOT . '/pack-library/*-pack', GLOB_ONLYDIR);
        $packs = array_map(
            static fn (string $dir): string => (string) preg_replace('/-pack$/', '', basename($dir)),
            is_array($dirs) ? $dirs : [],
        );
        sort($packs);

        $covered = [];
        foreach (self::documentedScenarioFiles() as $file) {
            /** @var array<string, mixed> $scenario */
            $scenario = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $init = is_array($scenario['init'] ?? null) ? $scenario['init'] : [];
            if (is_string($init['pack'] ?? null)) {
                $covered[] = $init['pack'];
            }
        }
        // A pack may back several scenarios (the lifecycle one plus regression guards), so
        // compare the SET of covered packs, not the list.
        $covered = array_values(array_unique($covered));
        sort($covered);

        self::assertSame($packs, $covered, 'a pack without a walkthrough scenario is an untested offer');
    }

    /**
     * @param array<string, string> $args
     * @return array{exit: int, json: array<string, mixed>, raw: string}
     */
    private function runCli(array $args): array
    {
        $output = new BufferedOutput();
        $exit = Cli::application()->run(new ArrayInput($args), $output);
        $raw = trim($output->fetch());
        $decoded = json_decode($raw, true);
        /** @var array<string, mixed> $json */
        $json = is_array($decoded) ? $decoded : [];

        return ['exit' => $exit, 'json' => $json, 'raw' => $raw];
    }
}
