/** 4px base grid. Tailwind spacing utilities are multiples of `spacingUnit`. */
export const spacingUnit = '0.25rem';

export const spacing = {
  0: '0', 0.5: '0.125rem', 1: '0.25rem', 1.5: '0.375rem', 2: '0.5rem', 3: '0.75rem', 4: '1rem', 5: '1.25rem',
  6: '1.5rem', 8: '2rem', 10: '2.5rem', 12: '3rem', 16: '4rem', 20: '5rem', 24: '6rem',
} as const;

export const radius = {
  none: '0', sm: '0.25rem', md: '0.375rem', lg: '0.5rem', xl: '0.75rem', '2xl': '1rem', full: '9999px',
} as const;

export const shadow = {
  xs: '0 1px 2px 0 rgb(18 25 38 / 0.05)',
  sm: '0 1px 3px 0 rgb(18 25 38 / 0.08), 0 1px 2px -1px rgb(18 25 38 / 0.06)',
  md: '0 4px 8px -2px rgb(18 25 38 / 0.08), 0 2px 4px -2px rgb(18 25 38 / 0.05)',
  lg: '0 12px 16px -4px rgb(18 25 38 / 0.08), 0 4px 6px -2px rgb(18 25 38 / 0.04)',
  xl: '0 24px 48px -12px rgb(18 25 38 / 0.18)',
} as const;

/** Mobile < md (768px) <= tablet < lg (1024px) <= desktop. */
export const breakpoints = { sm: '40rem', md: '48rem', lg: '64rem', xl: '80rem', '2xl': '96rem' } as const;

export const layout = {
  'page-max': '90rem',
  content: '80rem',
  prose: '42rem',
  form: '40rem',
  'sidebar': '16rem',
  'sidebar-collapsed': '4.5rem',
  'header': '4rem',
  'header-mobile': '3.5rem',
  'modal-sm': '25rem',
  'modal-md': '35rem',
  'modal-lg': '45rem',
  'drawer-sm': '25rem',
  'drawer-md': '35rem',
  'drawer-lg': '45rem',
  'toast': '22.5rem',
  'menu-min': '12rem',
} as const;

export const zIndex = { dropdown: 1000, sticky: 1100, drawer: 1200, modal: 1300, toast: 1400 } as const;

export const motion = {
  duration: { fast: '120ms', base: '180ms', slow: '240ms' },
  easing: { standard: 'cubic-bezier(0.2, 0, 0, 1)', exit: 'cubic-bezier(0.4, 0, 1, 1)' },
} as const;
