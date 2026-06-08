<?php

declare(strict_types=1);

namespace Summae\Runner\Subject;

/**
 * Das Prüfobjekt des Runners: eine Implementierung der Spezifikation.
 * Der Runner kennt nur dieses Interface — der Kern füllt es Job für Job.
 *
 * Fachliche Fehler (Fehlerkatalog) werden als SubjectError mit exaktem
 * E_*-Code geworfen; alles andere gilt als Crash der Implementierung.
 */
interface Subject
{
    /**
     * Frischen In-Memory-Mandanten aus dem setup-Block der Fixture bauen.
     * Platzhalter ($V1, …) sind zu diesem Zeitpunkt bereits durch
     * konkrete UUIDs ersetzt.
     *
     * @param array<string, mixed> $setup
     */
    public function setup(array $setup): void;

    /**
     * Eine Schreiboperation (steps[].op) ausführen.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed> Ergebnisdaten laut api.md
     *
     * @throws SubjectError
     */
    public function execute(string $op, array $input): array;

    /**
     * Eine Projektion berechnen (lesend, deterministisch).
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     *
     * @throws SubjectError
     */
    public function project(string $name, array $params): array;
}
