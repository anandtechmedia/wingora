/**
 * Colour tokens. Palettes are raw values; product code uses the semantic tokens below.
 * Every text/background pair in `contrastPairs` is verified against WCAG 2.2 AA in tests.
 */
export const palette = {
  white: '#FFFFFF',
  neutral: {
    25: '#FCFCFD', 50: '#F8FAFC', 100: '#F1F4F8', 200: '#E3E8EF', 300: '#CDD5DF', 400: '#9AA4B2',
    500: '#5F6B7C', 600: '#4B5565', 700: '#364152', 800: '#202939', 900: '#121926', 950: '#0B0F18',
  },
  navy: {
    50: '#F1F4F9', 100: '#DFE6F1', 200: '#BCCAE1', 300: '#8EA5CC', 400: '#6080B4', 500: '#41619A',
    600: '#314C7F', 700: '#283E68', 800: '#1E3054', 900: '#14223D', 950: '#0C1527',
  },
  indigo: {
    50: '#EEF0FF', 100: '#E0E3FF', 200: '#C7CBFE', 300: '#A4A8FC', 400: '#8183F7', 500: '#6463EE',
    600: '#4F46E0', 700: '#4137C4', 800: '#362F9E', 900: '#302C7D', 950: '#1D1A49',
  },
  green: { 50: '#ECFDF3', 200: '#ABEFC6', 600: '#067647', 700: '#085D3A' },
  amber: { 50: '#FFFAEB', 200: '#FEDF89', 600: '#B54708', 700: '#93370D' },
  red: { 50: '#FEF3F2', 200: '#FECDCA', 600: '#B42318', 700: '#912018' },
  blue: { 50: '#EFF8FF', 200: '#B2DDFF', 600: '#175CD3', 700: '#1849A9' },
} as const;

const p = palette;

export const semanticColors = {
  canvas: p.neutral[50],
  surface: p.white,
  'surface-muted': p.neutral[100],
  'surface-hover': p.neutral[50],
  'surface-selected': p.indigo[50],
  overlay: 'rgb(11 15 24 / 0.48)',

  fg: p.neutral[900],
  'fg-secondary': p.neutral[600],
  'fg-muted': p.neutral[500],
  'fg-disabled': p.neutral[400],
  'fg-inverse': p.white,
  'fg-link': p.indigo[700],
  'fg-brand': p.navy[900],

  line: p.neutral[200],
  'line-strong': p.neutral[300],
  /** Borders of form controls: at least 3:1 against surfaces (WCAG 1.4.11). */
  'line-control': p.neutral[500],
  focus: p.indigo[600],

  primary: p.navy[900],
  'primary-hover': p.navy[800],
  'primary-active': p.navy[950],
  'on-primary': p.white,

  accent: p.indigo[600],
  'accent-hover': p.indigo[700],
  'accent-subtle': p.indigo[50],
  'accent-fg': p.indigo[700],
  'on-accent': p.white,

  'nav-bg': p.navy[900],
  'nav-fg': p.navy[100],
  'nav-fg-muted': p.navy[300],
  'nav-hover-bg': p.navy[800],
  'nav-active-bg': p.navy[700],
  'nav-active-fg': p.white,
  'nav-indicator': p.indigo[300],
} as const;

/** Status colours. Meaning is never carried by colour alone: badges and alerts always include text or an icon. */
export const statusColors = {
  success: { subtle: p.green[50], line: p.green[200], fg: p.green[700], solid: p.green[600] },
  warning: { subtle: p.amber[50], line: p.amber[200], fg: p.amber[700], solid: p.amber[600] },
  danger: { subtle: p.red[50], line: p.red[200], fg: p.red[700], solid: p.red[600] },
  info: { subtle: p.blue[50], line: p.blue[200], fg: p.blue[700], solid: p.blue[600] },
  neutral: { subtle: p.neutral[100], line: p.neutral[300], fg: p.neutral[700], solid: p.neutral[600] },
} as const;

export type StatusTone = keyof typeof statusColors;

const s = semanticColors;

/** [foreground, background, minimum ratio, description] */
export const contrastPairs: ReadonlyArray<readonly [string, string, number, string]> = [
  [s.fg, s.surface, 4.5, 'body text on surface'],
  [s.fg, s.canvas, 4.5, 'body text on canvas'],
  [s['fg-secondary'], s.surface, 4.5, 'secondary text on surface'],
  [s['fg-muted'], s.surface, 4.5, 'muted text on surface'],
  [s['fg-muted'], s.canvas, 4.5, 'muted text on canvas'],
  [s['fg-muted'], s['surface-muted'], 4.5, 'muted text on muted surface'],
  [s['fg-link'], s.surface, 4.5, 'links on surface'],
  [s['accent-fg'], s['accent-subtle'], 4.5, 'accent text on accent tint'],
  [s.fg, s['surface-selected'], 4.5, 'text on selected row'],
  [s['on-primary'], s.primary, 4.5, 'primary button'],
  [s['on-primary'], s['primary-hover'], 4.5, 'primary button hover'],
  [s['on-accent'], s.accent, 4.5, 'accent button'],
  [s['on-accent'], s['accent-hover'], 4.5, 'accent button hover'],
  [s['nav-fg'], s['nav-bg'], 4.5, 'navigation text'],
  [s['nav-fg-muted'], s['nav-bg'], 4.5, 'navigation group labels'],
  [s['nav-fg'], s['nav-hover-bg'], 4.5, 'navigation hover'],
  [s['nav-active-fg'], s['nav-active-bg'], 4.5, 'active navigation item'],
  [s['line-control'], s.surface, 3, 'form control border'],
  [s.focus, s.surface, 3, 'focus ring on surface'],
  [s.focus, s.canvas, 3, 'focus ring on canvas'],
  [s['nav-indicator'], s['nav-bg'], 3, 'active navigation indicator'],
  ...Object.entries(statusColors).flatMap(([tone, c]) => [
    [c.fg, c.subtle, 4.5, `${tone} text on tint`] as const,
    [c.fg, s.surface, 4.5, `${tone} text on surface`] as const,
    [s['fg-inverse'], c.solid, 4.5, `white on ${tone} solid`] as const,
  ]),
];
