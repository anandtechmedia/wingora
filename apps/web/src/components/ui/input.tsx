import { forwardRef, type InputHTMLAttributes, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { controlClasses, Field, type ControlSize, type FieldBaseProps } from './field';

export interface InputProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'size' | 'id'>, FieldBaseProps {
  size?: ControlSize;
  leading?: ReactNode;
  trailing?: ReactNode;
  className?: string;
}

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
  { label, hideLabel, hint, error, required, optional, id, size = 'md', leading, trailing, className, ...props },
  ref,
) {
  return (
    <Field label={label} hideLabel={hideLabel} hint={hint} error={error} required={required} optional={optional} id={id} className={className}>
      {(control) => (
        <div className="relative flex items-center">
          {leading && <span className="pointer-events-none absolute start-3 flex text-fg-muted">{leading}</span>}
          <input ref={ref} {...control} {...props} className={cn(controlClasses(size, Boolean(error)), leading && 'ps-9', trailing && 'pe-9')} />
          {trailing && <span className="absolute end-2 flex text-fg-muted">{trailing}</span>}
        </div>
      )}
    </Field>
  );
});
