import type { HTMLAttributes, ReactNode } from 'react';
import { cn } from '@/lib/cn';

export interface CardProps extends Omit<HTMLAttributes<HTMLElement>, 'title'> {
  title?: ReactNode;
  /** Heading level for the title; match the page outline. */
  headingLevel?: 2 | 3 | 4;
  description?: ReactNode;
  actions?: ReactNode;
  footer?: ReactNode;
  padding?: 'md' | 'lg' | 'none';
}

export function Card({ title, headingLevel = 3, description, actions, footer, padding = 'md', className, children, ...props }: CardProps) {
  const pad = { md: 'p-(--card-padding)', lg: 'p-(--card-padding-lg)', none: '' }[padding];
  return (
    <section className={cn('min-w-0 rounded-(--card-radius) border border-line bg-surface shadow-(--card-shadow)', className)} {...props}>
      {(title || actions) && (
        <header className={cn('flex flex-wrap items-start justify-between gap-3', pad, children && padding !== 'none' && 'pb-0')}>
          <div className="flex flex-col gap-1">
            {title && (() => { const H = `h${headingLevel}` as const; return <H className="text-md font-semibold text-fg">{title}</H>; })()}
            {description && <p className="text-sm text-fg-secondary">{description}</p>}
          </div>
          {actions && <div className="flex items-center gap-2">{actions}</div>}
        </header>
      )}
      {children && <div className={pad}>{children}</div>}
      {footer && <footer className="border-t border-line px-(--card-padding) py-3">{footer}</footer>}
    </section>
  );
}
