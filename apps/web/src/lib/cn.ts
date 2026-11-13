/** Joins class names, skipping anything that is not a non-empty string (false, null, 0, …). */
export function cn(...classes: unknown[]): string {
  return classes.filter((c): c is string => typeof c === 'string' && c !== '').join(' ');
}
