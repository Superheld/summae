<?php

declare(strict_types=1);

namespace Summae\Core;

/**
 * Package marker, and the version this build calls itself. Nothing prints it — unlike
 * `CliPackage::VERSION`, which `summae --version` answers with — but it is the version of a
 * published package all the same, so `ReleaseVersionTest` holds it to the changelog too.
 */
final class CorePackage
{
    public const string VERSION = '0.15.0';

    private function __construct()
    {
    }
}
