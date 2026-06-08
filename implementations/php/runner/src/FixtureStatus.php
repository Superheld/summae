<?php

declare(strict_types=1);

namespace Summae\Runner;

enum FixtureStatus: string
{
    case Pass = 'PASS';
    case Fail = 'FAIL';
    case Crash = 'CRASH';
}
