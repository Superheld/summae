<?php

declare(strict_types=1);

namespace Summae\Cli;

use Summae\Cli\Command\InitCommand;
use Summae\Cli\Command\OpCommand;
use Summae\Cli\Command\ReportCommand;
use Symfony\Component\Console\Application;

/**
 * `summae` — accounting CLI (api.md F-IO-003).
 * Target user is an LLM operator: all output JSON (one response
 * per invocation, machine-readable), exit codes = error codes.
 */
final class Cli
{
    private function __construct()
    {
    }

    public static function application(): Application
    {
        $application = new Application('summae', CliPackage::VERSION);
        $application->add(new InitCommand());
        $application->add(new OpCommand());
        $application->add(new ReportCommand());
        $application->setCatchExceptions(false);
        $application->setAutoExit(false);

        return $application;
    }
}
