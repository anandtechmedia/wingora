import { useId, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { useControllable } from '@/lib/use-controllable';
import { choiceBoxClasses } from './checkbox';

export interface RadioOption {
  value: string;
  label: ReactNode;
  description?: ReactNode;
  disabled?: boolean;
}

export interface RadioGroupProps {
  label: ReactNode;
  options: RadioOption[];
  name?: string;
  value?: string;
  defaultValue?: string;
  onChange?: (value: string) => void;
  orientation?: 'vertical' | 'horizontal';
  error?: ReactNode;
  required?: boolean;
  className?: string;
}

/** Native radios in a <fieldset>: arrow keys move between options as browsers implement natively. */
export function RadioGroup({ label, options, name, value, defaultValue = '', onChange, orientation = 'vertical', error, required, className }: RadioGroupProps) {
  const generated = useId();
  const groupName = name ?? `radio-${generated}`;
  const [selected, setSelected] = useControllable(value, defaultValue, onChange);
  const errorId = error ? `${groupName}-error` : undefined;

  return (
    <fieldset className={cn('flex flex-col gap-2', className)} aria-describedby={errorId} aria-invalid={error ? true : undefined}>
      <legend className="mb-1 text-sm font-medium text-fg">
        {label}
        {required && <span className="ms-0.5 text-danger-fg" aria-hidden="true">*</span>}
      </legend>
      <div className={cn('flex gap-3', orientation === 'vertical' ? 'flex-col' : 'flex-row flex-wrap gap-x-6')}>
        {options.map((option) => {
          const id = `${groupName}-${option.value}`;
          return (
            <div key={option.value} className="flex items-start gap-2.5">
              <span className="relative mt-0.5 inline-flex">
                <input id={id} type="radio" name={groupName} value={option.value} checked={selected === option.value} disabled={option.disabled}
                  required={required} onChange={() => setSelected(option.value)}
                  aria-describedby={option.description ? `${id}-description` : undefined} className={cn(choiceBoxClasses, 'rounded-full')} />
                <span className="pointer-events-none absolute inset-0 m-auto size-1.5 rounded-full bg-surface opacity-0 peer-checked:opacity-100" />
              </span>
              <span className="flex flex-col">
                <label htmlFor={id} className="text-sm font-medium text-fg">{option.label}</label>
                {option.description && <span id={`${id}-description`} className="text-xs text-fg-muted">{option.description}</span>}
              </span>
            </div>
          );
        })}
      </div>
      {error && <p id={errorId} className="text-xs font-medium text-danger-fg">{error}</p>}
    </fieldset>
  );
}
