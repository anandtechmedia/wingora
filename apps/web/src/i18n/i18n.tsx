import { createTranslator, isLocaleLoaded, loadLocale, textDirection, type Translator } from '@wingora/localization';
import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';

interface I18nValue {
  locale: string;
  dir: 'ltr' | 'rtl';
  t: Translator;
}

const I18nContext = createContext<I18nValue>({ locale: 'en', dir: 'ltr', t: createTranslator('en') });

/**
 * Provides translations, the active locale (for Intl formatting) and text direction; syncs <html lang/dir>.
 * A catalog not loaded yet (see `loadLocale` in main.tsx) is fetched here, with English shown meanwhile.
 */
export function I18nProvider({ locale, children }: { locale: string; children: ReactNode }) {
  const [loaded, setLoaded] = useState(() => isLocaleLoaded(locale));
  if (loaded !== isLocaleLoaded(locale)) setLoaded(isLocaleLoaded(locale));

  useEffect(() => {
    if (isLocaleLoaded(locale)) return;
    let active = true;
    // On a failed fetch the page stays in English; a later locale change retries.
    loadLocale(locale).then(() => active && setLoaded(true), () => undefined);
    return () => {
      active = false;
    };
  }, [locale]);

  const value = useMemo<I18nValue>(() => ({ locale, dir: textDirection(locale), t: createTranslator(loaded ? locale : 'en') }), [locale, loaded]);

  useEffect(() => {
    document.documentElement.lang = locale;
    document.documentElement.dir = value.dir;
  }, [locale, value.dir]);

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n(): I18nValue {
  return useContext(I18nContext);
}
