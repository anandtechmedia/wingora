import { useState } from 'react';
import { ToastProvider } from '@/components/ui';
import { DesignSystemPage } from '@/design-system/design-system-page';
import { I18nProvider } from '@/i18n/i18n';
import { AppShell } from '@/layouts/app-shell';

export function App() {
  const [locale, setLocale] = useState('en-US');
  return (
    <I18nProvider locale={locale}>
      <ToastProvider>
        <AppShell>
          <DesignSystemPage locale={locale} onLocaleChange={setLocale} />
        </AppShell>
      </ToastProvider>
    </I18nProvider>
  );
}
