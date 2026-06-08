<?php

declare(strict_types=1);

namespace Summae\Cli;

use Summae\Cli\Command\InitCommand;
use Summae\Cli\Command\OpCommand;
use Summae\Cli\Command\ReportCommand;
use Symfony\Component\Console\Application;

/**
 * `rw` — Rechnungswesen-CLI (api.md F-IO-003).
 * Zielnutzer ist ein LLM-Operator: alle Ausgaben JSON (eine Antwort
 * pro Aufruf, maschinenlesbar), Exit-Codes = Fehlercodes.
 */
final class Cli
{
    private function __construct()
    {
    }

    public static function application(): Application
    {
        $application = new Application('rw', CliPackage::VERSION);
        $application->add(new InitCommand());
        $application->add(new OpCommand());
        $application->add(new ReportCommand());
        $application->setCatchExceptions(false);
        $application->setAutoExit(false);

        return $application;
    }
}
