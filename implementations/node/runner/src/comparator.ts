import { PlaceholderBag } from './placeholder-bag.js';

/**
 * Subset comparison per runner contract (testsuite/README.md): only fields
 * given in expect are checked; lists exact in length and order;
 * amounts exact as strings; "comment" keys are documentation and are ignored;
 * placeholders bind on first occurrence and compare thereafter.
 *
 * @returns deviations, empty = match.
 */
export function diff(
  expected: unknown,
  actual: unknown,
  bag: PlaceholderBag,
  path = '$',
): string[] {
  if (PlaceholderBag.isPlaceholder(expected)) {
    if (typeof actual !== 'string') {
      return [`${path}: placeholder ${expected} expects a string, is ${show(actual)}`];
    }
    if (!bag.has(expected)) {
      bag.bind(expected, actual);
      return [];
    }
    return bag.get(expected) === actual
      ? []
      : [`${path}: placeholder ${expected} = "${bag.get(expected)}", is "${actual}"`];
  }

  if (Array.isArray(expected)) {
    if (!Array.isArray(actual)) {
      return [`${path}: list expected, is ${show(actual)}`];
    }
    if (expected.length !== actual.length) {
      return [`${path}: list length ${expected.length} expected, is ${actual.length}`];
    }
    const diffs: string[] = [];
    expected.forEach((item, index) => {
      diffs.push(...diff(item, actual[index], bag, `${path}[${index}]`));
    });
    return diffs;
  }

  if (expected !== null && typeof expected === 'object') {
    if (actual === null || typeof actual !== 'object' || Array.isArray(actual)) {
      return [`${path}: object expected, is ${show(actual)}`];
    }
    const actualObject = actual as Record<string, unknown>;
    const diffs: string[] = [];
    for (const [key, value] of Object.entries(expected as Record<string, unknown>)) {
      if (key === 'comment') {
        continue; // documentation in the fixture, not comparison content
      }
      if (!Object.hasOwn(actualObject, key)) {
        diffs.push(`${path}.${key}: field missing in result`);
        continue;
      }
      diffs.push(...diff(value, actualObject[key], bag, `${path}.${key}`));
    }
    return diffs;
  }

  return expected === actual ? [] : [`${path}: expected ${show(expected)}, is ${show(actual)}`];
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
