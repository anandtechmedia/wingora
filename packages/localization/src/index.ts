import { en, type MessageKey, type Messages } from './catalogs/en.ts';
import { es } from './catalogs/es.ts';

export type { MessageKey, Messages };

/** Active locales ship translations; planned ones (Arabic, French) fall back to English until translated. */
export const activeLocales = ['en', 'es'] as const;
export const plannedLocales = ['ar', 'fr'] as const;
export type ActiveLocale = (typeof activeLocales)[number];

export const catalogs: Record<ActiveLocale, Messages> = { en, es };

const rtlLanguages = new Set(['ar', 'he', 'fa', 'ur']);

export function languageOf(locale: string): string {
  return locale.toLowerCase().split(/[-_]/)[0] ?? 'en';
}

export function resolveLocale(locale: string | undefined): ActiveLocale {
  const language = languageOf(locale ?? 'en');
  return (activeLocales as readonly string[]).includes(language) ? (language as ActiveLocale) : 'en';
}

export function textDirection(locale: string): 'ltr' | 'rtl' {
  return rtlLanguages.has(languageOf(locale)) ? 'rtl' : 'ltr';
}

export type Translator = (key: MessageKey, params?: Record<string, string | number>) => string;

export function createTranslator(locale: string | undefined): Translator {
  const messages = catalogs[resolveLocale(locale)];
  return (key, params) => {
    const template = messages[key] ?? en[key] ?? key;
    return params ? template.replace(/\{(\w+)\}/g, (match, name: string) => (name in params ? String(params[name]) : match)) : template;
  };
}
