import { describe, expect, it } from 'vitest';
import { catalogs, createTranslator, resolveLocale, textDirection } from './index.ts';

const placeholders = (s: string) => [...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort();

describe('catalogs', () => {
  it('every catalog has the same keys and placeholders as English', () => {
    for (const [locale, messages] of Object.entries(catalogs)) {
      expect(Object.keys(messages).sort(), locale).toEqual(Object.keys(catalogs.en).sort());
      for (const [key, value] of Object.entries(catalogs.en)) {
        expect(placeholders(messages[key as keyof typeof messages]), `${locale}:${key}`).toEqual(placeholders(value));
      }
    }
  });
});

describe('translator', () => {
  it('interpolates parameters', () => {
    expect(createTranslator('es-MX')('ui.pageSummary', { from: 1, to: 25, total: 80 })).toBe('1–25 de 80');
  });

  it('falls back to English for planned locales', () => {
    expect(resolveLocale('ar-AE')).toBe('en');
    expect(resolveLocale('fr-CA')).toBe('en');
    expect(createTranslator('fr-CA')('ui.close')).toBe('Close');
  });

  it('reports direction for right-to-left languages', () => {
    expect(textDirection('ar-AE')).toBe('rtl');
    expect(textDirection('es-MX')).toBe('ltr');
  });
});
