import { useId, useRef, type KeyboardEvent, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { useControllable } from '@/lib/use-controllable';

export interface TabItem {
  id: string;
  label: ReactNode;
  content: ReactNode;
  disabled?: boolean;
  badge?: ReactNode;
}

export interface TabsProps {
  /** Accessible name of the tab list. */
  label: string;
  tabs: TabItem[];
  value?: string;
  defaultValue?: string;
  onChange?: (id: string) => void;
  className?: string;
}

/** WAI-ARIA tabs with automatic activation: Left/Right (mirrored in RTL), Home and End move between tabs. */
export function Tabs({ label, tabs, value, defaultValue, onChange, className }: TabsProps) {
  const baseId = useId();
  const [active, setActive] = useControllable(value, defaultValue ?? tabs.find((t) => !t.disabled)?.id ?? '', onChange);
  const refs = useRef<Record<string, HTMLButtonElement | null>>({});
  const enabled = tabs.filter((t) => !t.disabled);

  const onKeyDown = (event: KeyboardEvent) => {
    const index = enabled.findIndex((t) => t.id === active);
    const rtl = document.documentElement.dir === 'rtl';
    const next = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1 }[event.key];
    let target: TabItem | undefined;
    if (next !== undefined) target = enabled[(index + next + enabled.length) % enabled.length];
    if (event.key === 'Home') target = enabled[0];
    if (event.key === 'End') target = enabled[enabled.length - 1];
    if (target) {
      event.preventDefault();
      setActive(target.id);
      refs.current[target.id]?.focus();
    }
  };

  return (
    <div className={className}>
      <div role="tablist" aria-label={label} className="flex gap-1 overflow-x-auto border-b border-line">
        {tabs.map((tab) => {
          const selected = tab.id === active;
          return (
            <button key={tab.id} ref={(el) => { refs.current[tab.id] = el; }} type="button" role="tab" id={`${baseId}-tab-${tab.id}`}
              aria-selected={selected} aria-controls={`${baseId}-panel-${tab.id}`} tabIndex={selected ? 0 : -1} disabled={tab.disabled}
              onClick={() => setActive(tab.id)} onKeyDown={onKeyDown}
              className={cn('-mb-px inline-flex h-10 items-center gap-2 border-b-2 px-3 text-sm font-medium whitespace-nowrap transition-colors duration-(--duration-fast)',
                selected ? 'border-accent text-fg' : 'border-transparent text-fg-secondary hover:border-line-strong hover:text-fg',
                'disabled:cursor-not-allowed disabled:opacity-50')}>
              {tab.label}
              {tab.badge}
            </button>
          );
        })}
      </div>
      {tabs.map((tab) => (
        <div key={tab.id} role="tabpanel" id={`${baseId}-panel-${tab.id}`} aria-labelledby={`${baseId}-tab-${tab.id}`} hidden={tab.id !== active}
          tabIndex={0} className="pt-4 focus-visible:outline-offset-4">
          {tab.id === active && tab.content}
        </div>
      ))}
    </div>
  );
}
