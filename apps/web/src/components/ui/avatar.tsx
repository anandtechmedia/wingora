import { useState } from 'react';
import { cn } from '@/lib/cn';

export interface AvatarProps {
  name: string;
  src?: string;
  size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl';
  /** Decorative avatars next to a visible name should set this to avoid repeating the name. */
  decorative?: boolean;
  className?: string;
}

const sizes = { xs: 'size-6 text-2xs', sm: 'size-8 text-xs', md: 'size-10 text-sm', lg: 'size-12 text-md', xl: 'size-16 text-xl' } as const;
const tints = ['bg-navy-100 text-navy-800', 'bg-indigo-100 text-indigo-800', 'bg-neutral-200 text-neutral-800'] as const;

export function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  const first = parts[0]?.[0] ?? '';
  const last = parts.length > 1 ? parts[parts.length - 1]![0] : '';
  return (first + last).toUpperCase();
}

/** Photo with an initials fallback (also used when the image fails to load). Tint is stable per name. */
export function Avatar({ name, src, size = 'md', decorative = false, className }: AvatarProps) {
  const [failed, setFailed] = useState(false);
  const tint = tints[[...name].reduce((sum, c) => sum + c.charCodeAt(0), 0) % tints.length];
  const a11y = decorative ? { 'aria-hidden': true as const } : { role: 'img' as const, 'aria-label': name };

  return (
    <span {...a11y} className={cn('relative inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full font-semibold select-none', sizes[size], tint, className)}>
      {src && !failed ? <img src={src} alt="" className="size-full object-cover" onError={() => setFailed(true)} /> : <span aria-hidden="true">{initials(name)}</span>}
    </span>
  );
}
