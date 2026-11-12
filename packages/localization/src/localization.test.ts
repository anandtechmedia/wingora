import { beforeAll, describe, expect, it } from 'vitest';
import { en } from './catalogs/en.ts';
import { es } from './catalogs/es.ts';
import { createTranslator, isLocaleLoaded, loadLocale, resolveLocale, textDirection } from './index.ts';

const catalogs = { en, es };

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
  beforeAll(() => loadLocale('es-MX'));

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

describe('loadLocale', () => {
  it('loads Spanish on demand and treats English as always loaded', async () => {
    expect(isLocaleLoaded('en-US')).toBe(true);
    await loadLocale('es');
    expect(isLocaleLoaded('es-MX')).toBe(true);
    expect(createTranslator('es')('ui.pageSummary', { from: 1, to: 25, total: 80 })).toBe('1–25 de 80');
  });
});
