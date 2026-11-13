import type { HTMLAttributes } from 'react';
import type { StatusTone } from '@wingora/design-tokens';
import { cn } from '@/lib/cn';

export interface BadgeProps extends HTMLAttributes<HTMLSpanElement> {
  tone?: StatusTone | 'accent';
  variant?: 'subtle' | 'solid' | 'outline';
  /** Leading dot. The text label always carries the meaning; colour is reinforcement only. */
  dot?: boolean;
  size?: 'sm' | 'md';
}

const subtle = {
  success: 'bg-success-subtle text-success-fg', warning: 'bg-warning-subtle text-warning-fg', danger: 'bg-danger-subtle text-danger-fg',
  info: 'bg-info-subtle text-info-fg', neutral: 'bg-neutral-subtle text-neutral-fg', accent: 'bg-accent-subtle text-accent-fg',
} as const;
const solid = {
  success: 'bg-success-solid text-fg-inverse', warning: 'bg-warning-solid text-fg-inverse', danger: 'bg-danger-solid text-fg-inverse',
  info: 'bg-info-solid text-fg-inverse', neutral: 'bg-neutral-solid text-fg-inverse', accent: 'bg-accent text-on-accent',
} as const;
const outline = {
  success: 'border border-success-line text-success-fg', warning: 'border border-warning-line text-warning-fg', danger: 'border border-danger-line text-danger-fg',
  info: 'border border-info-line text-info-fg', neutral: 'border border-neutral-line text-neutral-fg', accent: 'border border-indigo-200 text-accent-fg',
} as const;
const dots = {
  success: 'bg-success-solid', warning: 'bg-warning-solid', danger: 'bg-danger-solid', info: 'bg-info-solid', neutral: 'bg-neutral-solid', accent: 'bg-accent',
} as const;

export function Badge({ tone = 'neutral', variant = 'subtle', dot = false, size = 'md', className, children, ...props }: BadgeProps) {
  const styles = { subtle, solid, outline }[variant][tone];
  return (
    <span className={cn('inline-flex items-center gap-1.5 rounded-full font-medium whitespace-nowrap', size === 'sm' ? 'px-2 py-px text-2xs' : 'px-2.5 py-0.5 text-xs', styles, className)} {...props}>
      {dot && <span aria-hidden="true" className={cn('size-1.5 rounded-full', variant === 'solid' ? 'bg-current' : dots[tone])} />}
      {children}
    </span>
  );
}
