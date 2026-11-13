import { useCallback, useEffect, useId, useMemo, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { useDismiss } from '@/lib/use-dismiss';
import { CheckIcon } from './icons';
import { buttonClasses, type ButtonSize, type ButtonVariant } from './button';

export type DropdownItem =
  | { type?: 'item'; id: string; label: string; description?: string; icon?: ReactNode; onSelect: () => void; disabled?: boolean; tone?: 'default' | 'danger';
      /** Makes the item a `menuitemradio`; use for single-choice menus such as switchers. */
      checked?: boolean }
  | { type: 'separator'; id: string };

export interface DropdownProps {
  /** Trigger content. When it is icon-only, pass `label`. */
  trigger: ReactNode;
  /** Accessible name for icon-only triggers. */
  label?: string;
  items: DropdownItem[];
  align?: 'start' | 'end';
  variant?: ButtonVariant;
  size?: ButtonSize;
  className?: string;
  /** Non-interactive content shown above the items, e.g. the signed-in user. */
  header?: ReactNode;
  menuClassName?: string;
  /** Trigger is rendered with these classes instead of the button variant. */
  triggerClassName?: string;
}

/**
 * Menu button (WAI-ARIA APG): Enter/Space/ArrowDown open on the first item, ArrowUp on the last; arrows,
 * Home/End and type-ahead move focus; Escape and Tab close; focus returns to the trigger on close.
 */
export function Dropdown({ trigger, label, items, align = 'end', variant = 'secondary', size = 'md', className, header, menuClassName, triggerClassName }: DropdownProps) {
  const menuId = useId();
  const [open, setOpen] = useState(false);
  const [focusIndex, setFocusIndex] = useState(0);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);
  const itemRefs = useRef<Array<HTMLButtonElement | null>>([]);
  const actionable = items.filter((i): i is Extract<DropdownItem, { onSelect: () => void }> => i.type !== 'separator' && !i.disabled);

  const close = useCallback((restoreFocus = true) => {
    setOpen(false);
    if (restoreFocus) triggerRef.current?.focus();
  }, []);
  const refs = useMemo(() => [menuRef, triggerRef], []);
  useDismiss(refs, open, close);

  useEffect(() => {
    if (open) itemRefs.current[focusIndex]?.focus();
  }, [open, focusIndex]);

  const openAt = (index: number) => {
    setFocusIndex(index);
    setOpen(true);
  };

  const onTriggerKeyDown = (event: KeyboardEvent) => {
    if (['ArrowDown', 'Enter', ' '].includes(event.key)) {
      event.preventDefault();
      openAt(0);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      openAt(actionable.length - 1);
    }
  };

  const onMenuKeyDown = (event: KeyboardEvent) => {
    const count = actionable.length;
    const keys: Record<string, number> = { ArrowDown: (focusIndex + 1) % count, ArrowUp: (focusIndex - 1 + count) % count, Home: 0, End: count - 1 };
    if (event.key in keys) {
      event.preventDefault();
      setFocusIndex(keys[event.key]!);
    } else if (event.key === 'Tab') {
      close(false);
    } else if (event.key.length === 1 && /\S/.test(event.key)) {
      const start = (focusIndex + 1) % count;
      const order = [...actionable.slice(start), ...actionable.slice(0, start)];
      const match = order.find((i) => i.label.toLowerCase().startsWith(event.key.toLowerCase()));
      if (match) setFocusIndex(actionable.indexOf(match));
    }
  };

  return (
    <div className={cn('relative inline-flex', className)}>
      <button ref={triggerRef} type="button" aria-haspopup="menu" aria-expanded={open} aria-controls={open ? menuId : undefined} aria-label={label}
        onClick={() => (open ? close() : openAt(Math.max(0, actionable.findIndex((i) => i.checked))))} onKeyDown={onTriggerKeyDown} className={triggerClassName ?? buttonClasses(variant, size)}>
        {trigger}
      </button>
      {open && (
        <div ref={menuRef} id={menuId} role="menu" aria-label={label} tabIndex={-1} onKeyDown={onMenuKeyDown}
          className={cn('absolute top-full z-(--z-dropdown) mt-1 max-h-[70dvh] min-w-(--container-menu-min) overflow-y-auto rounded-lg border border-line bg-surface p-1 shadow-lg', align === 'end' ? 'end-0' : 'start-0', menuClassName)}>
          {header && <div className="border-b border-line px-2.5 pt-1.5 pb-2.5 mb-1">{header}</div>}
          {items.map((item) => {
            if (item.type === 'separator') return <div key={item.id} role="separator" className="my-1 h-px bg-line" />;
            const index = actionable.indexOf(item as (typeof actionable)[number]);
            return (
              <button key={item.id} ref={(el) => { if (index >= 0) itemRefs.current[index] = el; }} type="button" role={item.checked === undefined ? 'menuitem' : 'menuitemradio'} aria-checked={item.checked}
                tabIndex={-1} disabled={item.disabled} aria-disabled={item.disabled || undefined}
                onClick={() => { item.onSelect(); close(); }}
                className={cn('flex w-full items-center gap-2 rounded-md px-2.5 py-2 text-start text-sm focus:bg-surface-muted focus:outline-none hover:bg-surface-muted disabled:cursor-not-allowed disabled:opacity-50',
                  item.tone === 'danger' ? 'text-danger-fg' : 'text-fg')}>
                {item.icon && <span className="shrink-0 text-fg-muted">{item.icon}</span>}
                <span className="flex min-w-0 flex-1 flex-col">
                  <span className="truncate">{item.label}</span>
                  {item.description && <span className="truncate text-xs text-fg-muted">{item.description}</span>}
                </span>
                {item.checked && <CheckIcon className="shrink-0 text-accent-fg" />}
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}
