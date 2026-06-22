<?php

declare(strict_types=1);

namespace Summae\Runner\Subject;

/**
 * The runner's subject under test: an implementation of the specification.
 * The runner knows only this interface — the core fills it job by job.
 *
 * Domain errors (error catalog) are thrown as SubjectError with the exact
 * E_* code; everything else counts as a crash of the implementation.
 */
interface Subject
{
    /**
     * Build a fresh in-memory tenant from the fixture's setup block.
     * Placeholders ($V1, …) have already been replaced by
     * concrete UUIDs at this point.
     *
     * @param array<string, mixed> $setup
     */
    public function setup(array $setup): void;

    /**
     * Execute a write operation (steps[].op).
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed> result data per api.md
     *
     * @throws SubjectError
     */
    public function execute(string $op, array $input): array;

    /**
     * Compute a projection (reading, deterministic).
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     *
     * @throws SubjectError
     */
    public function project(string $name, array $params): array;
}
