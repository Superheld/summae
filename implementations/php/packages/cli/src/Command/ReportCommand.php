<?php

declare(strict_types=1);

namespace Summae\Cli\Command;

use Summae\Cli\ExitCodes;
use Summae\Cli\Workspace;
use Summae\Core\Composition\TenantOperations;
use Summae\Core\DomainError;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `summae report trialBalance --params '{"fiscalYear": 2026, "throughPeriod": 12}'`
 * — all projections, deterministic, asOf-capable.
 */
final class ReportCommand extends Command
{
    public function __construct()
    {
        parent::__construct('report');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Compute projection (trialBalance, cashBasisReport, vatReturn, …)')
            ->addArgument('projection', InputArgument::REQUIRED, 'Projection name per api.md')
            ->addOption('params', null, InputOption::VALUE_REQUIRED, 'Parameters as JSON or @file', '{}')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Working directory', '.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projection = is_string($input->getArgument('projection')) ? $input->getArgument('projection') : '';
        $directory = is_string($input->getOption('dir')) ? $input->getOption('dir') : '.';

        try {
            $raw = $input->getOption('params');
            $raw = is_string($raw) ? $raw : '{}';
            if (str_starts_with($raw, '@')) {
                $content = file_get_contents(substr($raw, 1));
                $raw = is_string($content) ? $content : '{}';
            }

            /** @var array<string, mixed> $params */
            $params = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            $tenant = Workspace::in($directory)->tenant();
            $result = (new TenantOperations($tenant))->project($projection, $params);
        } catch (DomainError $e) {
            $output->writeln(json_encode([
                'error' => $e->errorCode,
                'message' => $e->getMessage(),
                'details' => $e->details === [] ? new \stdClass() : $e->details,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return ExitCodes::for($e->errorCode);
        }

        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }
}
