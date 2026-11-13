import { useCallback, useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { cn } from '@/lib/cn';
import { useControllable } from '@/lib/use-controllable';
import { useDismiss } from '@/lib/use-dismiss';
import { useI18n } from '@/i18n/i18n';
import { controlClasses, Field, type ControlSize, type FieldBaseProps } from './field';
import { CalendarIcon, ChevronLeftIcon, ChevronRightIcon } from './icons';

/** ISO calendar date helpers (no time zone shifts: dates are handled as UTC midnight). */
const ISO = /^(\d{4})-(\d{2})-(\d{2})$/;
export function parseIsoDate(value: string): Date | null {
  const m = ISO.exec(value.trim());
  if (!m) return null;
  const date = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
  return date.getUTCMonth() === Number(m[2]) - 1 ? date : null;
}
export const toIsoDate = (d: Date) => d.toISOString().slice(0, 10);
const addDays = (d: Date, n: number) => new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate() + n));
function addMonths(d: Date, n: number) {
  const target = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + n, 1));
  const last = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0)).getUTCDate();
  return new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth(), Math.min(d.getUTCDate(), last)));
}
const todayUtc = () => { const n = new Date(); return new Date(Date.UTC(n.getFullYear(), n.getMonth(), n.getDate())); };

function firstDayOfWeek(locale: string): number {
  try {
    const info = (new Intl.Locale(locale) as Intl.Locale & { getWeekInfo?: () => { firstDay: number }; weekInfo?: { firstDay: number } });
    const firstDay = info.getWeekInfo?.().firstDay ?? info.weekInfo?.firstDay;
    return firstDay ? firstDay % 7 : 0;
  } catch {
    return 0;
  }
}

export interface DatePickerProps extends FieldBaseProps {
  /** ISO date `YYYY-MM-DD` or empty string. */
  value?: string;
  defaultValue?: string;
  onChange?: (value: string) => void;
  min?: string;
  max?: string;
  size?: ControlSize;
  disabled?: boolean;
  name?: string;
  className?: string;
  /** 0 = Sunday … 6 = Saturday. Defaults to the locale's convention. */
  weekStartsOn?: number;
}

/**
 * Text input (ISO format, unambiguous across markets) plus a keyboard-operable calendar:
 * arrows move by day/week, Home/End to week start/end, PageUp/PageDown by month (Shift: year),
 * Enter/Space select, Escape closes and returns focus to the trigger.
 */
export function DatePicker({ value, defaultValue = '', onChange, min, max, size = 'md', disabled, name, className, weekStartsOn, error, ...field }: DatePickerProps) {
  const { t, locale } = useI18n();
  const [current, setCurrent] = useControllable(value, defaultValue, onChange);
  const [text, setText] = useState(current);
  const [invalid, setInvalid] = useState(false);
  const [open, setOpen] = useState(false);
  const selected = parseIsoDate(current);
  const [focused, setFocused] = useState<Date>(selected ?? todayUtc());
  const triggerRef = useRef<HTMLButtonElement>(null);
  const popoverRef = useRef<HTMLDivElement>(null);
  const gridRef = useRef<HTMLTableElement>(null);
  const minDate = min ? parseIsoDate(min) : null;
  const maxDate = max ? parseIsoDate(max) : null;
  const firstDay = weekStartsOn ?? firstDayOfWeek(locale);

  const [synced, setSynced] = useState(current);
  if (synced !== current) {
    setSynced(current);
    setText(current);
    setInvalid(false);
  }

  const close = useCallback(() => {
    setOpen(false);
    triggerRef.current?.focus();
  }, []);
  const dismissRefs = useMemo(() => [popoverRef, triggerRef], []);
  useDismiss(dismissRefs, open, close);

  useEffect(() => {
    if (open) gridRef.current?.querySelector<HTMLButtonElement>('button[tabindex="0"]')?.focus();
  }, [open, focused]);

  const outOfRange = (d: Date) => Boolean((minDate && d < minDate) || (maxDate && d > maxDate));
  const fmtMonth = new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric', timeZone: 'UTC' });
  const fmtFull = new Intl.DateTimeFormat(locale, { dateStyle: 'full', timeZone: 'UTC' });
  const fmtWeekday = new Intl.DateTimeFormat(locale, { weekday: 'short', timeZone: 'UTC' });
  const fmtWeekdayLong = new Intl.DateTimeFormat(locale, { weekday: 'long', timeZone: 'UTC' });

  const monthStart = new Date(Date.UTC(focused.getUTCFullYear(), focused.getUTCMonth(), 1));
  const gridStart = addDays(monthStart, -((monthStart.getUTCDay() - firstDay + 7) % 7));
  const weeks = Array.from({ length: 6 }, (_, w) => Array.from({ length: 7 }, (_, d) => addDays(gridStart, w * 7 + d)));
  const today = toIsoDate(todayUtc());

  const select = (d: Date) => {
    if (outOfRange(d)) return;
    setCurrent(toIsoDate(d));
    setInvalid(false);
    close();
  };

  const onGridKeyDown = (event: KeyboardEvent) => {
    const rtl = document.documentElement.dir === 'rtl';
    const moves: Record<string, () => Date> = {
      ArrowLeft: () => addDays(focused, rtl ? 1 : -1),
      ArrowRight: () => addDays(focused, rtl ? -1 : 1),
      ArrowUp: () => addDays(focused, -7),
      ArrowDown: () => addDays(focused, 7),
      Home: () => addDays(focused, -((focused.getUTCDay() - firstDay + 7) % 7)),
      End: () => addDays(focused, 6 - ((focused.getUTCDay() - firstDay + 7) % 7)),
      PageUp: () => addMonths(focused, event.shiftKey ? -12 : -1),
      PageDown: () => addMonths(focused, event.shiftKey ? 12 : 1),
    };
    const move = moves[event.key];
    if (move) {
      event.preventDefault();
      setFocused(move());
    }
  };

  const commitText = () => {
    if (text.trim() === '') {
      setInvalid(false);
      setCurrent('');
      return;
    }
    const parsed = parseIsoDate(text);
    if (!parsed || outOfRange(parsed)) {
      setInvalid(true);
      return;
    }
    setInvalid(false);
    setCurrent(toIsoDate(parsed));
  };

  const errorMessage = error ?? (invalid ? t('ui.date.invalid') : undefined);

  return (
    <Field {...field} error={errorMessage} hint={field.hint ?? t('ui.date.format', { format: 'YYYY-MM-DD' })} className={cn('relative', className)}>
      {(control) => (
        <div className="relative">
          <input {...control} name={name} type="text" inputMode="numeric" autoComplete="off" placeholder="YYYY-MM-DD" value={text} disabled={disabled}
            onChange={(e) => setText(e.target.value)} onBlur={commitText} onKeyDown={(e) => e.key === 'Enter' && commitText()}
            className={cn(controlClasses(size, Boolean(errorMessage)), 'pe-10 tabular-nums')} />
          <button ref={triggerRef} type="button" disabled={disabled} aria-label={t('ui.date.choose')} aria-haspopup="dialog" aria-expanded={open}
            onClick={() => { setFocused(selected ?? todayUtc()); setOpen((o) => !o); }}
            className="absolute end-1 top-1/2 inline-flex size-8 -translate-y-1/2 items-center justify-center rounded-sm text-fg-muted hover:bg-surface-muted hover:text-fg disabled:opacity-55">
            <CalendarIcon />
          </button>
          {open && (
            <div ref={popoverRef} role="dialog" aria-modal="false" aria-label={t('ui.date.choose')}
              className="absolute end-0 top-full z-(--z-dropdown) mt-1 w-72 rounded-lg border border-line bg-surface p-3 shadow-lg">
              <div className="mb-2 flex items-center justify-between">
                <button type="button" aria-label={t('ui.date.previousMonth')} onClick={() => setFocused(addMonths(focused, -1))}
                  className="inline-flex size-8 items-center justify-center rounded-md text-fg-secondary hover:bg-surface-muted rtl:rotate-180"><ChevronLeftIcon /></button>
                <p className="text-sm font-semibold text-fg" aria-live="polite">{fmtMonth.format(monthStart)}</p>
                <button type="button" aria-label={t('ui.date.nextMonth')} onClick={() => setFocused(addMonths(focused, 1))}
                  className="inline-flex size-8 items-center justify-center rounded-md text-fg-secondary hover:bg-surface-muted rtl:rotate-180"><ChevronRightIcon /></button>
              </div>
              <table ref={gridRef} aria-label={fmtMonth.format(monthStart)} className="w-full border-collapse text-center">
                <thead>
                  <tr>{weeks[0]!.map((d) => (
                    <th key={d.getUTCDay()} scope="col" abbr={fmtWeekdayLong.format(d)} className="pb-1 text-2xs font-semibold uppercase tracking-wide text-fg-muted">{fmtWeekday.format(d)}</th>
                  ))}</tr>
                </thead>
                <tbody>
                  {weeks.map((week) => (
                    <tr key={toIsoDate(week[0]!)}>
                      {week.map((d) => {
                        const iso = toIsoDate(d);
                        const isSelected = iso === current;
                        const inMonth = d.getUTCMonth() === monthStart.getUTCMonth();
                        return (
                          <td key={iso} className="p-0.5">
                            <button type="button" tabIndex={iso === toIsoDate(focused) ? 0 : -1} disabled={outOfRange(d)}
                              aria-label={fmtFull.format(d)} aria-pressed={isSelected} aria-current={iso === today ? 'date' : undefined} onClick={() => select(d)}
                              onFocus={() => iso !== toIsoDate(focused) && setFocused(d)} onKeyDown={onGridKeyDown}
                              className={cn('inline-flex size-9 items-center justify-center rounded-md text-sm tabular-nums',
                                inMonth ? 'text-fg' : 'text-fg-muted',
                                isSelected ? 'bg-primary font-semibold text-on-primary' : 'hover:bg-surface-muted',
                                iso === today && !isSelected && 'font-semibold text-accent-fg underline underline-offset-4',
                                'disabled:cursor-not-allowed disabled:opacity-40')}>
                              {d.getUTCDate()}
                            </button>
                          </td>
                        );
                      })}
                    </tr>
                  ))}
                </tbody>
              </table>
              <div className="mt-2 flex justify-end">
                <button type="button" onClick={() => select(todayUtc())} className="rounded-md px-2 py-1 text-xs font-medium text-fg-link hover:bg-accent-subtle">{t('ui.date.today')}</button>
              </div>
            </div>
          )}
        </div>
      )}
    </Field>
  );
}
