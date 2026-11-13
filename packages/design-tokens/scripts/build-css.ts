import { writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { toThemeCss } from '../src/theme-css.ts';

const target = fileURLToPath(new URL('../css/tokens.css', import.meta.url));
writeFileSync(target, toThemeCss());
console.log(`Wrote ${target}`);
