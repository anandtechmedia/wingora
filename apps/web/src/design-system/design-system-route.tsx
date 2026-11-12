import { useState } from 'react';
import { I18nProvider } from '@/i18n/i18n';
import { DesignSystemPage } from './design-system-page';

export default function DesignSystemRoute() {
  const [locale, setLocale] = useState('en-US');
  return <I18nProvider locale={locale}><DesignSystemPage locale={locale} onLocaleChange={setLocale} /></I18nProvider>;
}
