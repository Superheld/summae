import { describe, it, expect } from 'vitest';
import { diff } from '../src/comparator.js';
import { PlaceholderBag } from '../src/placeholder-bag.js';

const bag = (): PlaceholderBag => new PlaceholderBag();

describe('Comparator.diff — subset comparison', () => {
  it('checks only given fields (subset)', () => {
    expect(diff({ a: 1 }, { a: 1, b: 2 }, bag())).toEqual([]);
  });

  it('reports deviating scalars', () => {
    expect(diff({ a: '1.00' }, { a: '1.01' }, bag())).toHaveLength(1);
  });

  it('reports missing fields', () => {
    expect(diff({ a: 1, b: 2 }, { a: 1 }, bag())).toEqual(['$.b: field missing in result']);
  });

  it('ignores comment keys', () => {
    expect(diff({ a: 1, comment: 'egal' }, { a: 1 }, bag())).toEqual([]);
  });

  it('checks lists exact in length and order', () => {
    expect(diff([1, 2], [1, 2], bag())).toEqual([]);
    expect(diff([1, 2], [1, 2, 3], bag())).toHaveLength(1);
    expect(diff([1, 2], [2, 1], bag())).toHaveLength(2);
  });

  it('compares nested', () => {
    expect(diff({ x: { y: [1] } }, { x: { y: [1] }, z: 9 }, bag())).toEqual([]);
  });

  it('binds placeholder on first occurrence, compares thereafter', () => {
    const b = bag();
    expect(diff({ id: '$E1' }, { id: 'uuid-a' }, b)).toEqual([]);
    expect(diff({ ref: '$E1' }, { ref: 'uuid-a' }, b)).toEqual([]); // same value → ok
    expect(diff({ ref: '$E1' }, { ref: 'uuid-b' }, b)).toHaveLength(1); // different value → diff
  });

  it('reports type errors (list vs. object)', () => {
    expect(diff([1], { 0: 1 }, bag())).toHaveLength(1);
    expect(diff({ a: 1 }, [1], bag())).toHaveLength(1);
  });
});
