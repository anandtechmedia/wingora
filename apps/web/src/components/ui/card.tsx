import type { HTMLAttributes, ReactNode } from 'react';
import { cn } from '@/lib/cn';

export interface CardProps extends Omit<HTMLAttributes<HTMLElement>, 'title'> {
  title?: ReactNode;
  description?: ReactNode;
  actions?: ReactNode;
  footer?: ReactNode;
  padding?: 'md' | 'lg' | 'none';
}

export function Card({ title, description, actions, footer, padding = 'md', className, children, ...props }: CardProps) {
  const pad = { md: 'p-(--card-padding)', lg: 'p-(--card-padding-lg)', none: '' }[padding];
  return (
    <section className={cn('rounded-(--card-radius) border border-line bg-surface shadow-(--card-shadow)', className)} {...props}>
      {(title || actions) && (
        <header className={cn('flex flex-wrap items-start justify-between gap-3', pad, children && padding !== 'none' && 'pb-0')}>
          <div className="flex flex-col gap-1">
            {title && <h3 className="text-md font-semibold text-fg">{title}</h3>}
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
