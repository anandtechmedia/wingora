/** Component-level tokens. Sizes meet the 44px touch-target minimum at `lg` (used on touch screens). */
export const components = {
  control: { 'height-sm': '2rem', 'height-md': '2.25rem', 'height-lg': '2.75rem', 'padding-x': '0.75rem', radius: 'md' },
  button: { 'height-sm': '2rem', 'height-md': '2.25rem', 'height-lg': '2.75rem', 'padding-x-sm': '0.75rem', 'padding-x-md': '0.875rem', 'padding-x-lg': '1.125rem', radius: 'md' },
  checkbox: { size: '1rem', radius: 'sm' },
  table: { 'row-compact': '2.5rem', 'row-comfortable': '3.25rem', 'cell-padding-x': '1rem', 'header-bg': 'canvas' },
  card: { padding: '1.25rem', 'padding-lg': '1.5rem', radius: 'lg', shadow: 'xs' },
  modal: { padding: '1.5rem', radius: 'xl', shadow: 'xl' },
  toast: { radius: 'lg', shadow: 'lg', 'duration-ms': '5000' },
  nav: { 'item-height': '2.25rem', 'item-radius': 'md', 'group-gap': '1.5rem' },
} as const;

/** Flattened `--{component}-{token}` custom properties for CSS. */
export function componentVariables(): Record<string, string> {
  const vars: Record<string, string> = {};
  for (const [component, tokens] of Object.entries(components)) {
    for (const [name, value] of Object.entries(tokens)) {
      vars[`--${component}-${name}`] = value;
    }
  }
  return vars;
}
