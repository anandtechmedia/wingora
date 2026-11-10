import { useId, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { useNavigate } from 'react-router';
import type { ModuleDefinition } from '@wingora/permissions';
import { Modal, SearchIcon } from '@/components/ui';
import { cn } from '@/lib/cn';
import { useI18n } from '@/i18n/i18n';
import { moduleIcons, moduleLabel } from './module-meta';

/** Command palette over the modules the user may open (combobox + listbox, APG). */
export function GlobalSearch({ open, onClose, modules }: { open: boolean; onClose: () => void; modules: ModuleDefinition[] }) {
  const { t, locale } = useI18n();
  const navigate = useNavigate();
  const inputRef = useRef<HTMLInputElement>(null);
  const listId = useId();
  const [query, setQuery] = useState('');
  const [active, setActive] = useState(0);

  const results = useMemo(() => {
    const q = query.trim().toLocaleLowerCase(locale);
    return modules.filter((m) => !q || t(moduleLabel(m.id)).toLocaleLowerCase(locale).includes(q));
  }, [modules, query, t, locale]);

  const close = () => {
    setQuery('');
    setActive(0);
    onClose();
  };

  const go = (module: ModuleDefinition | undefined) => {
    if (!module) return;
    close();
    navigate(`/${module.path}`);
  };

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (results.length === 0) return;
    const moves: Record<string, number> = { ArrowDown: (active + 1) % results.length, ArrowUp: (active - 1 + results.length) % results.length, Home: 0, End: results.length - 1 };
    if (event.key in moves) {
      event.preventDefault();
      setActive(moves[event.key]!);
    } else if (event.key === 'Enter') {
      event.preventDefault();
      go(results[active]);
    }
  };

  const optionId = (index: number) => `${listId}-${index}`;

  return (
    <Modal open={open} onClose={close} title={t('search.title')} initialFocus={inputRef} size="md">
      <div className="relative">
        <SearchIcon className="pointer-events-none absolute start-3 top-1/2 -translate-y-1/2 text-fg-muted" />
        <input ref={inputRef} type="text" role="combobox" aria-expanded="true" aria-controls={listId} aria-autocomplete="list"
          aria-activedescendant={results.length ? optionId(active) : undefined} aria-label={t('search.placeholder')}
          placeholder={t('search.placeholder')} value={query} onKeyDown={onKeyDown}
          onChange={(e) => { setQuery(e.target.value); setActive(0); }}
          className="h-(--control-height-lg) w-full rounded-md border border-line-control bg-surface ps-9 pe-3 text-md text-fg placeholder:text-fg-muted focus-visible:border-focus" />
      </div>
      <p className="mt-4 mb-2 text-2xs font-semibold tracking-wide text-fg-muted uppercase" aria-hidden="true">{t('search.results')}</p>
      <div id={listId} role="listbox" aria-label={t('search.results')} className="flex max-h-80 flex-col gap-0.5 overflow-y-auto">
        {results.map((m, index) => {
          const Icon = moduleIcons[m.id];
          return (
            // Keyboard interaction is handled by the combobox input (aria-activedescendant).
            // eslint-disable-next-line jsx-a11y/click-events-have-key-events
            <div key={m.id} id={optionId(index)} role="option" tabIndex={-1} aria-selected={index === active} onClick={() => go(m)} onMouseMove={() => setActive(index)}
              className={cn('flex cursor-pointer items-center gap-3 rounded-md px-3 py-2.5 text-sm', index === active ? 'bg-surface-selected text-fg' : 'text-fg-secondary')}>
              <Icon size={18} />
              <span className="flex-1">{t(moduleLabel(m.id))}</span>
            </div>
          );
        })}
      </div>
      {results.length === 0 && <p role="status" className="py-6 text-center text-sm text-fg-secondary">{t('search.noResults', { query })}</p>}
      <p className="mt-4 border-t border-line pt-3 text-xs text-fg-muted">{t('search.hint')}</p>
    </Modal>
  );
}
