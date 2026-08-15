<?php

declare(strict_types=1);

namespace Summae\Cli\Command;

use Summae\Core\DomainError;
use Summae\Cli\ErrorOutput;
use Summae\Cli\PackLibrary;
use Summae\Cli\Workspace;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `summae init --name="Muster GmbH" [--currency=EUR] [--rules=regeln.json]`
 *
 * The rules file carries app-layer data: accounts, taxCodes, taxProfile,
 * dimensionTypes/-Values, ruleModules (mappings, gwgThresholds, …).
 */
final class InitCommand extends Command
{
    public function __construct()
    {
        parent::__construct('init');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Create workspace (summae.json + SQLite database)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Tenant name')
            ->addOption('currency', null, InputOption::VALUE_REQUIRED, 'Base currency (ISO 4217)', 'EUR')
            ->addOption('rules', null, InputOption::VALUE_REQUIRED, 'JSON file with pack data (alternative to --pack)')
            ->addOption('pack', null, InputOption::VALUE_REQUIRED, 'Shipped pack from the library (e.g. "de", "default")')
            ->addOption('pack-library', null, InputOption::VALUE_REQUIRED, 'Path to the pack library')
            ->addOption('first-fiscal-year', null, InputOption::VALUE_REQUIRED, 'Create first fiscal year (e.g. 2026)')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Working directory', '.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            return $this->createWorkspace($input, $output);
        } catch (\Throwable $e) {
            return ErrorOutput::report($output, $e);
        }
    }

    /** The command body — extracted so `execute` can wrap it in the error boundary. */
    /**
     * `--first-fiscal-year` went through an int cast unchecked: `""` became 0 and the workspace
     * was created for the year 0000, which nothing could address afterwards.
     */
    private function parseFirstFiscalYear(mixed $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        if (!is_string($raw) || trim($raw) === '' || preg_match('/^[0-9]+$/', trim($raw)) !== 1) {
            throw new DomainError(
                'E_INPUT_INVALID',
                'init: --first-fiscal-year must be a positive whole number',
                ['firstFiscalYear' => DomainError::rejectedValue($raw)],
            );
        }

        $year = (int) trim($raw);
        if ($year <= 0) {
            throw new DomainError(
                'E_INPUT_INVALID',
                'init: --first-fiscal-year must be a positive whole number',
                ['firstFiscalYear' => $raw],
            );
        }

        return $year;
    }

    private function createWorkspace(InputInterface $input, OutputInterface $output): int
    {
        $directory = is_string($input->getOption('dir')) ? $input->getOption('dir') : '.';
        $name = is_string($input->getOption('name')) ? $input->getOption('name') : 'Mandant';
        $currency = is_string($input->getOption('currency')) ? $input->getOption('currency') : 'EUR';

        // The help calls them alternatives; the code took the pack branch and dropped --rules
        // without a word, so "pack plus my own accounts" silently became "pack".
        $pack = $input->getOption('pack');
        $rulesFile = $input->getOption('rules');
        if (is_string($pack) && is_string($rulesFile)) {
            throw new DomainError(
                'E_INPUT_INVALID',
                'init: --pack and --rules are alternatives — pass one of them',
                ['pack' => $pack, 'rules' => $rulesFile],
            );
        }

        $firstFiscalYear = $this->parseFirstFiscalYear($input->getOption('first-fiscal-year'));

        /** @var array<string, mixed> $rules */
        $rules = [];
        if (is_string($pack)) {
            $libDir = is_string($input->getOption('pack-library'))
                ? $input->getOption('pack-library')
                : PackLibrary::defaultDir();
            $rules = PackLibrary::packToRules($pack, $libDir);
            if ($firstFiscalYear !== null) {
                $rules['fiscalYears'] = [[
                    'year' => $firstFiscalYear,
                    'start' => sprintf('%04d-01-01', $firstFiscalYear),
                    'end' => sprintf('%04d-12-31', $firstFiscalYear),
                ]];
            }
        } else {
            if (is_string($rulesFile)) {
                $raw = file_get_contents($rulesFile);
                /** @var array<string, mixed> $rules */
                $rules = json_decode(is_string($raw) ? $raw : '{}', true, 512, JSON_THROW_ON_ERROR);
            }
        }

        $workspace = Workspace::in($directory);
        $workspace->initialize($name, $currency, $rules);

        // Create master data from the rules file directly (SF-01: immediately postable).
        // Everything past this point can still fail on the rules file's content, and a workspace
        // is already on disk. Without the rollback, one bad account left a config and a database
        // behind and every retry answered "Workspace already exists": a dead-end directory whose
        // only way out is deleting files by hand.
        $created = ['accounts' => 0, 'fiscalYears' => 0];

        try {
            $tenant = $workspace->tenant();

            foreach (is_array($rules['accounts'] ?? null) ? $rules['accounts'] : [] as $account) {
                if (is_array($account)) {
                    /** @var array<string, mixed> $account */
                    $tenant->ledger->createAccount($account);
                    $created['accounts']++;
                }
            }

            foreach (is_array($rules['fiscalYears'] ?? null) ? $rules['fiscalYears'] : [] as $fiscalYear) {
                if (is_array($fiscalYear)) {
                    /** @var array<string, mixed> $fiscalYear */
                    $tenant->ledger->createFiscalYear($fiscalYear);
                    $created['fiscalYears']++;
                }
            }
        } catch (\Throwable $e) {
            $workspace->discard();

            throw $e;
        }

        $output->writeln(json_encode([
            'initialized' => true,
            'tenant' => $name,
            'baseCurrency' => $currency,
            'created' => $created,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }
}
