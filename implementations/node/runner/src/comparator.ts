import { PlaceholderBag } from './placeholder-bag.js';

/**
 * Teilmengen-Vergleich nach Runner-Kontrakt (testsuite/README.md): nur in
 * expect angegebene Felder werden geprüft; Listen exakt in Länge und Reihenfolge;
 * Beträge exakt als Strings; "comment"-Schlüssel sind Doku und werden ignoriert;
 * Platzhalter binden beim ersten Auftreten und vergleichen danach.
 *
 * @returns Abweichungen, leer = Übereinstimmung.
 */
export function diff(
  expected: unknown,
  actual: unknown,
  bag: PlaceholderBag,
  path = '$',
): string[] {
  if (PlaceholderBag.isPlaceholder(expected)) {
    if (typeof actual !== 'string') {
      return [`${path}: Platzhalter ${expected} erwartet einen String, ist ${show(actual)}`];
    }
    if (!bag.has(expected)) {
      bag.bind(expected, actual);
      return [];
    }
    return bag.get(expected) === actual
      ? []
      : [`${path}: Platzhalter ${expected} = "${bag.get(expected)}", ist "${actual}"`];
  }

  if (Array.isArray(expected)) {
    if (!Array.isArray(actual)) {
      return [`${path}: Liste erwartet, ist ${show(actual)}`];
    }
    if (expected.length !== actual.length) {
      return [`${path}: Listenlänge ${expected.length} erwartet, ist ${actual.length}`];
    }
    const diffs: string[] = [];
    expected.forEach((item, index) => {
      diffs.push(...diff(item, actual[index], bag, `${path}[${index}]`));
    });
    return diffs;
  }

  if (expected !== null && typeof expected === 'object') {
    if (actual === null || typeof actual !== 'object' || Array.isArray(actual)) {
      return [`${path}: Objekt erwartet, ist ${show(actual)}`];
    }
    const actualObject = actual as Record<string, unknown>;
    const diffs: string[] = [];
    for (const [key, value] of Object.entries(expected as Record<string, unknown>)) {
      if (key === 'comment') {
        continue; // Doku in der Fixture, kein Vergleichsinhalt
      }
      if (!Object.hasOwn(actualObject, key)) {
        diffs.push(`${path}.${key}: Feld fehlt im Ergebnis`);
        continue;
      }
      diffs.push(...diff(value, actualObject[key], bag, `${path}.${key}`));
    }
    return diffs;
  }

  return expected === actual ? [] : [`${path}: erwartet ${show(expected)}, ist ${show(actual)}`];
}

function show(value: unknown): string {
  let json: string;
  try {
    json = JSON.stringify(value) ?? String(value);
  } catch {
    json = String(value);
  }
  return json.length > 120 ? `${json.slice(0, 117)}…` : json;
}
