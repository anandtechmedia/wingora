export const fontFamily = {
  sans: '"Inter", ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", "Noto Sans Arabic", sans-serif',
  mono: 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace',
} as const;

/** Type scale in rem with line heights. 14px (`sm`) is the default UI size; `md` (16px) is used for form controls on touch screens. */
export const fontSize = {
  '2xs': { size: '0.6875rem', lineHeight: '1rem' },
  xs: { size: '0.75rem', lineHeight: '1rem' },
  sm: { size: '0.875rem', lineHeight: '1.25rem' },
  md: { size: '1rem', lineHeight: '1.5rem' },
  lg: { size: '1.125rem', lineHeight: '1.75rem' },
  xl: { size: '1.25rem', lineHeight: '1.75rem' },
  '2xl': { size: '1.5rem', lineHeight: '2rem' },
  '3xl': { size: '1.875rem', lineHeight: '2.375rem' },
  '4xl': { size: '2.25rem', lineHeight: '2.75rem' },
} as const;

export const fontWeight = { regular: 400, medium: 500, semibold: 600, bold: 700 } as const;

export const letterSpacing = { tight: '-0.015em', normal: '0', wide: '0.04em' } as const;

/** Named text styles used across components. */
export const textStyles = {
  display: { size: '3xl', weight: 'semibold', tracking: 'tight' },
  pageTitle: { size: '2xl', weight: 'semibold', tracking: 'tight' },
  sectionTitle: { size: 'lg', weight: 'semibold', tracking: 'tight' },
  cardTitle: { size: 'md', weight: 'semibold', tracking: 'normal' },
  body: { size: 'sm', weight: 'regular', tracking: 'normal' },
  label: { size: 'sm', weight: 'medium', tracking: 'normal' },
  caption: { size: 'xs', weight: 'regular', tracking: 'normal' },
  overline: { size: '2xs', weight: 'semibold', tracking: 'wide' },
} as const;
