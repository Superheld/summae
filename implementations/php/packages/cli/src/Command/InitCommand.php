<?php

declare(strict_types=1);

namespace Summae\Cli\Command;

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
        $directory = is_string($input->getOption('dir')) ? $input->getOption('dir') : '.';
        $name = is_string($input->getOption('name')) ? $input->getOption('name') : 'Mandant';
        $currency = is_string($input->getOption('currency')) ? $input->getOption('currency') : 'EUR';

        /** @var array<string, mixed> $rules */
        $rules = [];
        $pack = $input->getOption('pack');
        if (is_string($pack)) {
            $libDir = is_string($input->getOption('pack-library'))
                ? $input->getOption('pack-library')
                : PackLibrary::defaultDir();
            $rules = PackLibrary::packToRules($pack, $libDir);
            $ffy = $input->getOption('first-fiscal-year');
            if (is_string($ffy)) {
                $year = (int) $ffy;
                $rules['fiscalYears'] = [[
                    'year' => $year,
                    'start' => sprintf('%04d-01-01', $year),
                    'end' => sprintf('%04d-12-31', $year),
                ]];
            }
        } else {
            $rulesFile = $input->getOption('rules');
            if (is_string($rulesFile)) {
                $raw = file_get_contents($rulesFile);
                /** @var array<string, mixed> $rules */
                $rules = json_decode(is_string($raw) ? $raw : '{}', true, 512, JSON_THROW_ON_ERROR);
            }
        }

        $workspace = Workspace::in($directory);
        $workspace->initialize($name, $currency, $rules);

        // Create master data from the rules file directly (SF-01: immediately postable).
        $tenant = $workspace->tenant();
        $created = ['accounts' => 0, 'fiscalYears' => 0];

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

        $output->writeln(json_encode([
            'initialized' => true,
            'tenant' => $name,
            'baseCurrency' => $currency,
            'created' => $created,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }
}
