import { canonicalJson } from '@summae/core';
import type { Fixture } from './fixture-loader.js';
import { FixtureRunner } from './fixture-runner.js';
import type { FixtureResult, SuiteResult, TraceEntry } from './result.js';
import type { SubjectFactory } from './subject.js';

/**
 * Kompletter Suite-Lauf inkl. Doppellauf-Determinismus-Check (Runner-Kontrakt
 * Punkt 4): beide Läufe müssen nach Normalisierung identische Spuren liefern.
 * UUIDs werden auf Auftrittsreihenfolge normalisiert — Fixtures vergleichen nie
 * ID-Werte (determinismus.md §5).
 */
export class SuiteRunner {
  constructor(
    private readonly subjectFactory: SubjectFactory,
    private readonly fixtureRunner: FixtureRunner = new FixtureRunner(),
  ) {}

  run(fixtures: Fixture[], filter?: string): SuiteResult {
    const selected =
      filter === undefined ? fixtures : fixtures.filter((fixture) => fixture.name.includes(filter));

    const firstRun = selected.map((fixture) =>
      this.fixtureRunner.run(fixture, this.subjectFactory.create()),
    );
    const secondRun = selected.map((fixture) =>
      this.fixtureRunner.run(fixture, this.subjectFactory.create()),
    );

    const determinismBreaks: string[] = [];
    firstRun.forEach((result: FixtureResult, index) => {
      if (normalizedTrace(result.trace) !== normalizedTrace(secondRun[index]!.trace)) {
        determinismBreaks.push(result.fixture);
      }
    });

    return { results: firstRun, determinismBreaks };
  }
}

const UUID = /[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/g;

/** Kanonisches JSON der Spur, UUIDs durch Auftrittsindex ersetzt. */
function normalizedTrace(trace: TraceEntry[]): string {
  const json = canonicalJson(trace);
  const seen = new Map<string, string>();
  return json.replace(UUID, (match) => {
    let label = seen.get(match);
    if (label === undefined) {
      label = `#uuid${seen.size + 1}`;
      seen.set(match, label);
    }
    return label;
  });
}
