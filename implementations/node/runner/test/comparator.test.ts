import { describe, it, expect } from 'vitest';
import { diff } from '../src/comparator.js';
import { PlaceholderBag } from '../src/placeholder-bag.js';

const bag = (): PlaceholderBag => new PlaceholderBag();

describe('Comparator.diff — Teilmengen-Vergleich', () => {
  it('prüft nur angegebene Felder (Teilmenge)', () => {
    expect(diff({ a: 1 }, { a: 1, b: 2 }, bag())).toEqual([]);
  });

  it('meldet abweichende Skalare', () => {
    expect(diff({ a: '1.00' }, { a: '1.01' }, bag())).toHaveLength(1);
  });

  it('meldet fehlende Felder', () => {
    expect(diff({ a: 1, b: 2 }, { a: 1 }, bag())).toEqual(['$.b: Feld fehlt im Ergebnis']);
  });

  it('ignoriert comment-Schlüssel', () => {
    expect(diff({ a: 1, comment: 'egal' }, { a: 1 }, bag())).toEqual([]);
  });

  it('prüft Listen exakt in Länge und Reihenfolge', () => {
    expect(diff([1, 2], [1, 2], bag())).toEqual([]);
    expect(diff([1, 2], [1, 2, 3], bag())).toHaveLength(1);
    expect(diff([1, 2], [2, 1], bag())).toHaveLength(2);
  });

  it('vergleicht verschachtelt', () => {
    expect(diff({ x: { y: [1] } }, { x: { y: [1] }, z: 9 }, bag())).toEqual([]);
  });

  it('bindet Platzhalter beim ersten Auftreten, vergleicht danach', () => {
    const b = bag();
    expect(diff({ id: '$E1' }, { id: 'uuid-a' }, b)).toEqual([]);
    expect(diff({ ref: '$E1' }, { ref: 'uuid-a' }, b)).toEqual([]); // gleicher Wert → ok
    expect(diff({ ref: '$E1' }, { ref: 'uuid-b' }, b)).toHaveLength(1); // anderer Wert → Diff
  });

  it('meldet Typfehler (Liste vs. Objekt)', () => {
    expect(diff([1], { 0: 1 }, bag())).toHaveLength(1);
    expect(diff({ a: 1 }, [1], bag())).toHaveLength(1);
  });
});
