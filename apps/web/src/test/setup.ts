import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { loadLocale } from '@wingora/localization';
import { afterEach, beforeAll } from 'vitest';

beforeAll(() => loadLocale('es'));

afterEach(() => cleanup());
