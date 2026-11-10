import { forwardRef, useEffect, useId, useImperativeHandle, useRef, type InputHTMLAttributes, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { CheckIcon, MinusIcon } from './icons';

export interface CheckboxProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
  label: ReactNode;
  hideLabel?: boolean;
  description?: ReactNode;
  indeterminate?: boolean;
}

export const choiceBoxClasses = 'peer size-(--checkbox-size) shrink-0 appearance-none border border-line-control bg-surface transition-colors duration-(--duration-fast) checked:border-primary checked:bg-primary disabled:cursor-not-allowed disabled:opacity-55';

export const Checkbox = forwardRef<HTMLInputElement, CheckboxProps>(function Checkbox(
  { label, hideLabel, description, indeterminate = false, className, id: idProp, ...props },
  forwarded,
) {
  const ref = useRef<HTMLInputElement>(null);
  useImperativeHandle(forwarded, () => ref.current!);
  const generated = useId();
  const id = idProp ?? `checkbox-${generated}`;

  useEffect(() => {
    if (ref.current) ref.current.indeterminate = indeterminate;
  }, [indeterminate]);

  return (
    <div className={cn('flex items-start gap-2.5', className)}>
      <span className="relative mt-0.5 inline-flex">
        <input ref={ref} id={id} type="checkbox" aria-describedby={description ? `${id}-description` : undefined}
          className={cn(choiceBoxClasses, 'rounded-sm', indeterminate && 'border-primary bg-primary')} {...props} />
        <CheckIcon size={12} strokeWidth={3} className={cn('pointer-events-none absolute inset-0 m-auto text-on-primary opacity-0 peer-checked:opacity-100', indeterminate && 'hidden')} />
        {indeterminate && <MinusIcon size={12} strokeWidth={3} className="pointer-events-none absolute inset-0 m-auto text-on-primary" />}
      </span>
      <span className={cn('flex flex-col', hideLabel && 'sr-only')}>
        <label htmlFor={id} className="text-sm font-medium text-fg">{label}</label>
        {description && <span id={`${id}-description`} className="text-xs text-fg-muted">{description}</span>}
      </span>
    </div>
  );
});
