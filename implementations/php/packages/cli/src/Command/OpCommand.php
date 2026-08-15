<?php

declare(strict_types=1);

namespace Summae\Cli\Command;

use Summae\Cli\ErrorOutput;
use Summae\Cli\Workspace;
use Summae\Core\Composition\TenantOperations;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `summae op post --input '{"entryDate": …}'` — all write operations
 * of api.md in one call (SF-02: `summae op postVoucher --input …`).
 * Input as a JSON string or `@datei.json`.
 */
final class OpCommand extends Command
{
    public function __construct()
    {
        parent::__construct('op');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Run write operation (post, postVoucher, settle, …)')
            ->addArgument('operation', InputArgument::REQUIRED, 'Operation name per api.md')
            ->addOption('input', null, InputOption::VALUE_REQUIRED, 'Input as JSON or @file', '{}')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Working directory', '.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $operation = is_string($input->getArgument('operation')) ? $input->getArgument('operation') : '';
        $directory = is_string($input->getOption('dir')) ? $input->getOption('dir') : '.';

        try {
            $payload = $this->payload($input);
            $workspace = Workspace::in($directory);
            $tenant = $workspace->tenant();
            $result = (new TenantOperations($tenant))->execute($operation, $payload);

            // The ledger persists itself through the database adapter; a mapping does not — it
            // lives in a registry rebuilt from summae.json on every call, so the import has to be
            // written back or it is forgotten the moment this process ends (R-4).
            if ($operation === 'importMapping' && is_array($payload['mapping'] ?? null)) {
                /** @var array<string, mixed> $mapping */
                $mapping = $payload['mapping'];
                $workspace->rememberMapping($mapping);
            }
        } catch (\Throwable $e) {
            return ErrorOutput::report($output, $e);
        }

        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(InputInterface $input): array
    {
        $raw = $input->getOption('input');
        $raw = is_string($raw) ? $raw : '{}';

        if (str_starts_with($raw, '@')) {
            $content = file_get_contents(substr($raw, 1));
            $raw = is_string($content) ? $content : '{}';
        }

        /** @var array<string, mixed> */
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }
}
