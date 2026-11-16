import { useEffect } from 'react';

const HEX = /^#[0-9a-f]{6}$/i;

/** Darkens a #RRGGBB color by mixing it with black. */
function shade(hex: string, amount: number): string {
  const n = parseInt(hex.slice(1), 16);
  const ch = (shift: number) => Math.round(((n >> shift) & 0xff) * (1 - amount)).toString(16).padStart(2, '0');
  return `#${ch(16)}${ch(8)}${ch(0)}`.toUpperCase();
}

/**
 * Applies an organization's brand color as the accent while mounted. The server only accepts colors with
 * at least 4.5:1 contrast against white, so accent buttons and links stay readable.
 */
export function useBrandColor(color: string | null | undefined) {
  useEffect(() => {
    if (!color || !HEX.test(color)) return;
    const root = document.documentElement.style;
    const vars: Record<string, string> = { '--color-accent': color, '--color-accent-hover': shade(color, 0.14), '--color-accent-fg': shade(color, 0.14) };
    for (const [k, v] of Object.entries(vars)) root.setProperty(k, v);
    return () => { for (const k of Object.keys(vars)) root.removeProperty(k); };
  }, [color]);
}
