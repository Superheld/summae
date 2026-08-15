<?php

declare(strict_types=1);

namespace Summae\Cli;

use Summae\Core\DomainError;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Every failure leaves as one line of JSON — the CLI's output contract holds for
 * unanticipated errors too, not just for domain errors (api.md F-IO-003).
 *
 * A caller that parses stdout must never be handed a stack trace: an agent or app
 * cannot branch on one, and a crash mid-pipeline looks like a transport failure
 * rather than a rejected input. Anything that is not a `DomainError` (malformed JSON
 * input, a value object rejecting a parameter, a missing workspace) becomes
 * `E_UNEXPECTED` with exit code 1 — which is what the error-code contract already
 * reserves for "unknown error", so no catalogue entry is added or moved.
 *
 * `E_UNEXPECTED` is deliberately NOT a catalogue code: seeing it means summae failed
 * to classify the problem, and that is a bug report, not a case to handle.
 *
 * Counterpart to Node's `reportError` in `cli.ts` — same shape, same exit codes.
 */
final class ErrorOutput
{
    private function __construct()
    {
    }

    /** Writes the JSON error line and returns the exit code to hand back. */
    public static function report(OutputInterface $output, \Throwable $error): int
    {
        if ($error instanceof DomainError) {
            $output->writeln(json_encode([
                'error' => $error->errorCode,
                'message' => $error->getMessage(),
                'details' => $error->details === [] ? new \stdClass() : $error->details,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return ExitCodes::for($error->errorCode);
        }

        $output->writeln(json_encode([
            'error' => 'E_UNEXPECTED',
            'message' => $error->getMessage(),
            'details' => new \stdClass(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return 1;
    }
}
