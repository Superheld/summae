import { describe, it, expect } from 'vitest';
import { PlaceholderBag } from '../src/placeholder-bag.js';

describe('PlaceholderBag', () => {
  it('recognizes placeholders', () => {
    expect(PlaceholderBag.isPlaceholder('$V1')).toBe(true);
    expect(PlaceholderBag.isPlaceholder('$open_item_2')).toBe(true);
    expect(PlaceholderBag.isPlaceholder('1200')).toBe(false);
    expect(PlaceholderBag.isPlaceholder('$')).toBe(false);
    expect(PlaceholderBag.isPlaceholder(42)).toBe(false);
  });

  it('binds unknown placeholders via onUnknown and replaces known ones', () => {
    const bag = new PlaceholderBag();
    let counter = 0;
    const fresh = (): string => `id-${++counter}`;

    const resolved = bag.resolve({ a: '$V1', b: ['$V2', '$V1'], c: 7 }, fresh);

    expect(resolved).toEqual({ a: 'id-1', b: ['id-2', 'id-1'], c: 7 });
  });

  it('throws on colliding binding', () => {
    const bag = new PlaceholderBag();
    bag.bind('$V1', 'a');
    expect(() => bag.bind('$V1', 'b')).toThrow();
    expect(() => bag.get('$unbound')).toThrow();
  });
});
