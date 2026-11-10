import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { useI18n } from '@/i18n/i18n';
import { Checkbox } from './checkbox';
import { ChevronDownIcon, ChevronUpIcon } from './icons';
import { EmptyState, ErrorState, LoadingState } from './states';

export type SortDirection = 'asc' | 'desc';
export interface SortState {
  columnId: string;
  direction: SortDirection;
}

export interface DataTableColumn<T> {
  id: string;
  header: ReactNode;
  cell: (row: T) => ReactNode;
  sortable?: boolean;
  align?: 'start' | 'end' | 'center';
  /** Hide on narrow screens (table layout only). */
  hideBelow?: 'md' | 'lg';
  /** Marks the column used as the title of mobile cards. */
  primary?: boolean;
  width?: string;
}

export interface DataTableProps<T> {
  caption: string;
  hideCaption?: boolean;
  columns: DataTableColumn<T>[];
  rows: T[];
  getRowId: (row: T) => string;
  sort?: SortState | null;
  onSortChange?: (sort: SortState) => void;
  selectable?: boolean;
  selectedIds?: string[];
  onSelectionChange?: (ids: string[]) => void;
  loading?: boolean;
  error?: string | null;
  onRetry?: () => void;
  empty?: ReactNode;
  density?: 'compact' | 'comfortable';
  /** Below `md`, render rows as stacked cards instead of a horizontally scrolling table. */
  stackOnMobile?: boolean;
  footer?: ReactNode;
  className?: string;
}

const align = { start: 'text-start', end: 'text-end', center: 'text-center' } as const;
const hide = { md: 'hidden md:table-cell', lg: 'hidden lg:table-cell' } as const;

/**
 * Semantic <table> with a caption, scoped headers, aria-sort on sortable columns and labelled row selection.
 * Server-side sorting/pagination: the table renders `rows` as given and reports intent via callbacks.
 */
export function DataTable<T>({
  caption, hideCaption = true, columns, rows, getRowId, sort, onSortChange, selectable = false, selectedIds = [], onSelectionChange,
  loading = false, error, onRetry, empty, density = 'compact', stackOnMobile = true, footer, className,
}: DataTableProps<T>) {
  const { t } = useI18n();
  const ids = rows.map(getRowId);
  const selected = new Set(selectedIds);
  const allSelected = ids.length > 0 && ids.every((id) => selected.has(id));
  const someSelected = !allSelected && ids.some((id) => selected.has(id));
  const toggleAll = () => onSelectionChange?.(allSelected ? selectedIds.filter((id) => !ids.includes(id)) : [...new Set([...selectedIds, ...ids])]);
  const toggle = (id: string) => onSelectionChange?.(selected.has(id) ? selectedIds.filter((s) => s !== id) : [...selectedIds, id]);
  const rowHeight = density === 'compact' ? 'h-(--table-row-compact)' : 'h-(--table-row-comfortable)';
  const primary = columns.find((c) => c.primary) ?? columns[0];

  const body = loading ? <LoadingState variant="skeleton" rows={5} />
    : error ? <ErrorState description={error} onRetry={onRetry} />
    : rows.length === 0 ? (empty ?? <EmptyState title={t('ui.empty.title')} />)
    : null;

  return (
    <div className={cn('overflow-hidden rounded-lg border border-line bg-surface shadow-xs', className)}>
      {selectable && selectedIds.length > 0 && (
        <p className="border-b border-line bg-surface-selected px-(--table-cell-padding-x) py-2 text-sm font-medium text-fg" aria-live="polite">
          {t('ui.table.selected', { count: selectedIds.length })}
        </p>
      )}
      <div className={cn('overflow-x-auto', stackOnMobile && 'hidden md:block')}>
        <table className="w-full border-collapse text-sm" aria-busy={loading || undefined}>
          <caption className={cn('px-(--table-cell-padding-x) py-3 text-start font-semibold text-fg', hideCaption && 'sr-only')}>{caption}</caption>
          <thead className="bg-canvas">
            <tr className="border-b border-line">
              {selectable && (
                <th scope="col" className="w-10 ps-(--table-cell-padding-x)">
                  <Checkbox label={t('ui.table.selectAll')} hideLabel checked={allSelected} indeterminate={someSelected} onChange={toggleAll} disabled={rows.length === 0} />
                </th>
              )}
              {columns.map((column) => {
                const active = sort?.columnId === column.id;
                return (
                  <th key={column.id} scope="col" style={column.width ? { width: column.width } : undefined}
                    aria-sort={column.sortable ? (active ? (sort!.direction === 'asc' ? 'ascending' : 'descending') : 'none') : undefined}
                    className={cn('h-10 px-(--table-cell-padding-x) text-xs font-semibold tracking-wide text-fg-secondary uppercase whitespace-nowrap', align[column.align ?? 'start'], column.hideBelow && hide[column.hideBelow])}>
                    {column.sortable && onSortChange ? (
                      <button type="button" className="-mx-1 inline-flex items-center gap-1 rounded-sm px-1 uppercase hover:text-fg"
                        onClick={() => onSortChange({ columnId: column.id, direction: active && sort!.direction === 'asc' ? 'desc' : 'asc' })}>
                        {column.header}
                        <span className={cn(active ? 'text-fg' : 'text-fg-disabled')}>
                          {active && sort!.direction === 'desc' ? <ChevronDownIcon size={14} /> : <ChevronUpIcon size={14} />}
                        </span>
                      </button>
                    ) : column.header}
                  </th>
                );
              })}
            </tr>
          </thead>
          {!body && (
            <tbody>
              {rows.map((row) => {
                const id = getRowId(row);
                return (
                  <tr key={id} aria-selected={selectable ? selected.has(id) : undefined}
                    className={cn('border-b border-line last:border-b-0 hover:bg-surface-hover', selected.has(id) && 'bg-surface-selected hover:bg-surface-selected')}>
                    {selectable && (
                      <td className="ps-(--table-cell-padding-x)">
                        <Checkbox label={`${t('ui.table.selectRow')} ${id}`} hideLabel checked={selected.has(id)} onChange={() => toggle(id)} />
                      </td>
                    )}
                    {columns.map((column) => (
                      <td key={column.id} className={cn(rowHeight, 'px-(--table-cell-padding-x) text-fg', align[column.align ?? 'start'], column.hideBelow && hide[column.hideBelow])}>
                        {column.cell(row)}
                      </td>
                    ))}
                  </tr>
                );
              })}
            </tbody>
          )}
        </table>
        {body}
      </div>

      {stackOnMobile && (
        <div className="md:hidden">
          <p className="sr-only">{caption}</p>
          {body ?? (
            <ul className="divide-y divide-line">
              {rows.map((row) => {
                const id = getRowId(row);
                return (
                  <li key={id} className={cn('flex gap-3 p-4', selected.has(id) && 'bg-surface-selected')}>
                    {selectable && <Checkbox label={`${t('ui.table.selectRow')} ${id}`} hideLabel checked={selected.has(id)} onChange={() => toggle(id)} />}
                    <dl className="grid flex-1 grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
                      <dt className="sr-only">{primary?.header}</dt>
                      <dd className="col-span-2 font-medium text-fg">{primary?.cell(row)}</dd>
                      {columns.filter((c) => c !== primary).map((column) => (
                        <div key={column.id} className="contents">
                          <dt className="text-fg-muted">{column.header}</dt>
                          <dd className="text-end text-fg">{column.cell(row)}</dd>
                        </div>
                      ))}
                    </dl>
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      )}
      {footer && <div className="border-t border-line px-(--table-cell-padding-x) py-3">{footer}</div>}
    </div>
  );
}
