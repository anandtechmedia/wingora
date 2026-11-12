import { render, type RenderOptions } from '@testing-library/react';
import type { ReactElement, ReactNode } from 'react';
import { ToastProvider } from '@/components/ui';
import { I18nProvider } from '@/i18n/i18n';

export function renderUi(ui: ReactElement, { locale = 'en-US', ...options }: RenderOptions & { locale?: string } = {}) {
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <I18nProvider locale={locale}><ToastProvider>{children}</ToastProvider></I18nProvider>
  );
  return render(ui, { wrapper: Wrapper, ...options });
}
