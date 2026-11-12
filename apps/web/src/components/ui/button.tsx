import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { Spinner } from './icons';

export type ButtonVariant = 'primary' | 'secondary' | 'tertiary' | 'danger' | 'link';
export type ButtonSize = 'sm' | 'md' | 'lg';

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
  size?: ButtonSize;
  loading?: boolean;
  leadingIcon?: ReactNode;
  trailingIcon?: ReactNode;
  fullWidth?: boolean;
}

const variants: Record<ButtonVariant, string> = {
  primary: 'bg-primary text-on-primary shadow-xs hover:bg-primary-hover active:bg-primary-active',
  secondary: 'bg-surface text-fg border border-line-strong shadow-xs hover:bg-surface-hover hover:border-line-control',
  tertiary: 'bg-transparent text-fg-secondary hover:bg-surface-muted hover:text-fg',
  danger: 'bg-danger-solid text-fg-inverse shadow-xs hover:bg-danger-fg',
  link: 'bg-transparent text-fg-link underline-offset-4 hover:underline px-0! h-auto!',
};

const sizes: Record<ButtonSize, string> = {
  sm: 'h-(--button-height-sm) px-(--button-padding-x-sm) text-xs gap-1.5',
  md: 'h-(--button-height-md) px-(--button-padding-x-md) text-sm gap-2',
  lg: 'h-(--button-height-lg) px-(--button-padding-x-lg) text-md gap-2',
};

export const buttonClasses = (variant: ButtonVariant = 'primary', size: ButtonSize = 'md', fullWidth = false) =>
  cn(
    'inline-flex shrink-0 items-center justify-center rounded-md font-medium whitespace-nowrap select-none',
    'transition-colors duration-(--duration-fast) ease-standard',
    'disabled:cursor-not-allowed disabled:opacity-55 aria-busy:cursor-progress',
    variants[variant], sizes[size], fullWidth && 'w-full',
  );

/** Actions. Defaults to `type="button"` so it never submits a form by accident. */
export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { variant = 'primary', size = 'md', loading = false, leadingIcon, trailingIcon, fullWidth, className, children, disabled, type = 'button', ...props },
  ref,
) {
  return (
    <button ref={ref} type={type} disabled={disabled || loading} aria-busy={loading || undefined}
      className={cn(buttonClasses(variant, size, fullWidth), className)} {...props}>
      {loading ? <Spinner size={size === 'lg' ? 18 : 16} /> : leadingIcon}
      {children}
      {!loading && trailingIcon}
    </button>
  );
});

export interface IconButtonProps extends Omit<ButtonProps, 'children' | 'leadingIcon' | 'trailingIcon' | 'aria-label'> {
  /** Accessible name; also shown as a native tooltip. */
  label: string;
  icon: ReactNode;
}

const iconSizes: Record<ButtonSize, string> = { sm: 'w-(--button-height-sm)', md: 'w-(--button-height-md)', lg: 'w-(--button-height-lg)' };

export const IconButton = forwardRef<HTMLButtonElement, IconButtonProps>(function IconButton(
  { label, icon, variant = 'tertiary', size = 'md', className, ...props },
  ref,
) {
  return (
    <Button ref={ref} variant={variant} size={size} aria-label={label} title={label} className={cn('px-0!', iconSizes[size], className)} {...props}>
      {icon}
    </Button>
  );
});
