import { screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { describe, expect, it } from 'vitest';
import { renderUi } from '@/test/render';
import { AppErrorPage, isStaleBuildError } from './error-pages';

describe('application error page', () => {
  it('asks for a reload when a screen file from an earlier release is gone', () => {
    renderUi(<MemoryRouter><AppErrorPage error={new TypeError('Failed to fetch dynamically imported module: https://x/assets/settings-C6ZXgfJK.js')} /></MemoryRouter>);
    expect(screen.getByRole('heading', { name: 'Wingora has been updated' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Reload page' })).toBeInTheDocument();
  });

  it('keeps the generic message for other errors and never shows details', () => {
    renderUi(<MemoryRouter><AppErrorPage error={new Error('secret internals')} /></MemoryRouter>);
    expect(screen.getByRole('heading', { name: 'Something went wrong' })).toBeInTheDocument();
    expect(screen.queryByText(/secret internals/)).not.toBeInTheDocument();
    expect(isStaleBuildError('Importing a module script failed.')).toBe(true);
  });
});
