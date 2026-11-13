import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { contrastPairs, contrastRatio, toThemeCss } from './index.ts';

describe('colour contrast (WCAG 2.2 AA)', () => {
  it.each(contrastPairs.map(([fg, bg, min, label]) => [label, fg, bg, min] as const))('%s', (_label, fg, bg, min) => {
    expect(contrastRatio(fg, bg)).toBeGreaterThanOrEqual(min);
  });

  it('computes known ratios', () => {
    expect(contrastRatio('#000000', '#FFFFFF')).toBeCloseTo(21, 5);
    expect(contrastRatio('#FFFFFF', '#FFFFFF')).toBeCloseTo(1, 5);
  });
});

describe('generated CSS', () => {
  it('is up to date with the tokens (run `pnpm tokens` after changing them)', () => {
    const committed = readFileSync(new URL('../css/tokens.css', import.meta.url), 'utf8');
    expect(committed).toBe(toThemeCss());
  });

  it('clears Tailwind defaults so only tokens are available', () => {
    expect(toThemeCss()).toContain('--color-*: initial;');
    expect(toThemeCss()).toContain('--color-primary: #14223D;');
  });
});
