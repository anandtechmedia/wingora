import type { ReactNode } from 'react';
import { useI18n } from '@/i18n/i18n';
import { ChevronRightIcon } from './icons';

export interface Crumb {
  label: string;
  /** Rendered link for every crumb except the current page. */
  render?: (children: ReactNode, className: string) => ReactNode;
}

/** WAI-ARIA breadcrumb: a labelled nav with an ordered list; the last item is the current page. */
export function Breadcrumbs({ items }: { items: Crumb[] }) {
  const { t } = useI18n();
  if (items.length === 0) return null;
  const linkClass = 'rounded-sm text-fg-secondary hover:text-fg hover:underline';
  return (
    <nav aria-label={t('shell.breadcrumb')}>
      <ol className="flex flex-wrap items-center gap-1.5 text-sm">
        {items.map((item, index) => {
          const last = index === items.length - 1;
          return (
            <li key={`${item.label}-${index}`} className="flex items-center gap-1.5">
              {last || !item.render
                ? <span aria-current={last ? 'page' : undefined} className={last ? 'font-medium text-fg' : 'text-fg-secondary'}>{item.label}</span>
                : item.render(item.label, linkClass)}
              {!last && <ChevronRightIcon size={14} className="text-fg-muted rtl:rotate-180" />}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
