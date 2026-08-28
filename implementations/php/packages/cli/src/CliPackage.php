<?php

declare(strict_types=1);

namespace Summae\Cli;

/**
 * Package marker, and the version this build calls itself — counterpart to Node's `CLI_VERSION`.
 * Bumped with the release; `ReleaseVersionTest` holds it to the changelog.
 */
final class CliPackage
{
    public const string VERSION = '0.16.0';

    private function __construct()
    {
    }
}
