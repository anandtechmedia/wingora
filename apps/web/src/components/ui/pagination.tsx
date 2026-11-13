import { cn } from '@/lib/cn';
import { useI18n } from '@/i18n/i18n';
import { ChevronLeftIcon, ChevronRightIcon } from './icons';

export interface PaginationProps {
  page: number;
  pageCount: number;
  onPageChange: (page: number) => void;
  total?: number;
  pageSize?: number;
  className?: string;
}

/** Page numbers with ellipses: 1 … 4 5 [6] 7 8 … 20 */
export function pageItems(page: number, pageCount: number): Array<number | 'gap'> {
  if (pageCount <= 7) return Array.from({ length: pageCount }, (_, i) => i + 1);
  const pages = new Set([1, pageCount, page - 1, page, page + 1].filter((p) => p >= 1 && p <= pageCount));
  if (page <= 3) [2, 3, 4].forEach((p) => pages.add(p));
  if (page >= pageCount - 2) [pageCount - 3, pageCount - 2, pageCount - 1].forEach((p) => pages.add(p));
  const sorted = [...pages].sort((a, b) => a - b);
  return sorted.flatMap((p, i) => (i > 0 && p - sorted[i - 1]! > 1 ? ['gap' as const, p] : [p]));
}

const itemClass = 'inline-flex h-(--button-height-sm) min-w-(--button-height-sm) items-center justify-center rounded-md px-2 text-sm tabular-nums';

export function Pagination({ page, pageCount, onPageChange, total, pageSize, className }: PaginationProps) {
  const { t, locale } = useI18n();
  const nf = new Intl.NumberFormat(locale);
  const summary = total !== undefined && pageSize
    ? t('ui.pageSummary', { from: nf.format(total === 0 ? 0 : (page - 1) * pageSize + 1), to: nf.format(Math.min(page * pageSize, total)), total: nf.format(total) })
    : null;

  return (
    <nav aria-label={t('ui.pagination')} className={cn('flex flex-wrap items-center justify-between gap-3', className)}>
      {summary ? <p className="text-sm text-fg-secondary tabular-nums">{summary}</p> : <span />}
      <ul className="flex items-center gap-1">
        <li>
          <button type="button" className={cn(itemClass, 'gap-1 text-fg-secondary hover:bg-surface-muted disabled:opacity-40')} disabled={page <= 1}
            onClick={() => onPageChange(page - 1)} aria-label={t('ui.previousPage')}>
            <ChevronLeftIcon className="rtl:rotate-180" />
          </button>
        </li>
        {pageItems(page, pageCount).map((item, i) => item === 'gap' ? (
          <li key={`gap-${i}`} aria-hidden="true" className="hidden px-1 text-fg-muted sm:block">…</li>
        ) : (
          <li key={item} className={cn(item === page ? 'block' : 'hidden sm:block')}>
            <button type="button" onClick={() => onPageChange(item)} aria-current={item === page ? 'page' : undefined}
              aria-label={t('ui.goToPage', { page: nf.format(item) })}
              className={cn(itemClass, item === page ? 'bg-primary font-semibold text-on-primary' : 'text-fg-secondary hover:bg-surface-muted')}>
              {nf.format(item)}
            </button>
          </li>
        ))}
        <li>
          <button type="button" className={cn(itemClass, 'gap-1 text-fg-secondary hover:bg-surface-muted disabled:opacity-40')} disabled={page >= pageCount}
            onClick={() => onPageChange(page + 1)} aria-label={t('ui.nextPage')}>
            <ChevronRightIcon className="rtl:rotate-180" />
          </button>
        </li>
      </ul>
    </nav>
  );
}
