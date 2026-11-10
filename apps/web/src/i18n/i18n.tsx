import { createTranslator, textDirection, type Translator } from '@wingora/localization';
import { createContext, useContext, useEffect, useMemo, type ReactNode } from 'react';

interface I18nValue {
  locale: string;
  dir: 'ltr' | 'rtl';
  t: Translator;
}

const I18nContext = createContext<I18nValue>({ locale: 'en', dir: 'ltr', t: createTranslator('en') });

/** Provides translations, the active locale (for Intl formatting) and text direction; syncs <html lang/dir>. */
export function I18nProvider({ locale, children }: { locale: string; children: ReactNode }) {
  const value = useMemo<I18nValue>(() => ({ locale, dir: textDirection(locale), t: createTranslator(locale) }), [locale]);

  useEffect(() => {
    document.documentElement.lang = locale;
    document.documentElement.dir = value.dir;
  }, [locale, value.dir]);

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n(): I18nValue {
  return useContext(I18nContext);
}
