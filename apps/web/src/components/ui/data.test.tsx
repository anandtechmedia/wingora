import { act, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { renderUi } from '@/test/render';
import { axeViolations } from '@/test/axe';
import { Avatar, Badge, DataTable, DatePicker, ErrorState, LoadingState, Pagination, pageItems, useToast } from '.';

type Row = { id: string; name: string };
const rows: Row[] = [{ id: 'E-1', name: 'Ana Ruiz' }, { id: 'E-2', name: 'Omar Haddad' }];
const none: Row[] = [];
const columns = [{ id: 'name', header: 'Name', sortable: true, cell: (r: Row) => r.name }];

describe('DataTable', () => {
  it('exposes caption, sort state and labelled selection', async () => {
    const onSort = vi.fn();
    const onSelect = vi.fn();
    const { container } = renderUi(<DataTable caption="People" columns={columns} rows={rows} getRowId={(r) => r.id}
      sort={{ columnId: 'name', direction: 'asc' }} onSortChange={onSort} selectable selectedIds={[]} onSelectionChange={onSelect} stackOnMobile={false} />);

    const table = screen.getByRole('table', { name: 'People' });
    expect(within(table).getByRole('columnheader', { name: /Name/ })).toHaveAttribute('aria-sort', 'ascending');
    await userEvent.click(within(table).getByRole('button', { name: /Name/ }));
    expect(onSort).toHaveBeenCalledWith({ columnId: 'name', direction: 'desc' });
    await userEvent.click(screen.getByRole('checkbox', { name: 'Select all rows' }));
    expect(onSelect).toHaveBeenCalledWith(['E-1', 'E-2']);
    expect(await axeViolations(container)).toEqual([]);
  });

  it('renders loading, empty and error states', async () => {
    const onRetry = vi.fn();
    const { rerender } = renderUi(<DataTable caption="People" columns={columns} rows={none} getRowId={(r) => r.id} loading stackOnMobile={false} />);
    expect(screen.getByRole('status')).toHaveTextContent('Loading…');
    rerender(<DataTable caption="People" columns={columns} rows={none} getRowId={(r) => r.id} stackOnMobile={false} />);
    expect(screen.getByText('Nothing here yet')).toBeInTheDocument();
    rerender(<DataTable caption="People" columns={columns} rows={none} getRowId={(r) => r.id} error="Failed" onRetry={onRetry} stackOnMobile={false} />);
    await userEvent.click(within(screen.getByRole('alert')).getByRole('button', { name: 'Try again' }));
    expect(onRetry).toHaveBeenCalled();
  });
});

describe('Pagination', () => {
  it('computes page ranges with gaps', () => {
    expect(pageItems(1, 5)).toEqual([1, 2, 3, 4, 5]);
    expect(pageItems(6, 20)).toEqual([1, 'gap', 5, 6, 7, 'gap', 20]);
    expect(pageItems(1, 20)).toEqual([1, 2, 3, 4, 'gap', 20]);
  });

  it('marks the current page, disables previous on page 1 and localizes the summary', async () => {
    const onChange = vi.fn();
    renderUi(<Pagination page={1} pageCount={12} total={287} pageSize={25} onPageChange={onChange} />, { locale: 'es-MX' });
    expect(screen.getByRole('navigation', { name: 'Paginación' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Página 1' })).toHaveAttribute('aria-current', 'page');
    expect(screen.getByRole('button', { name: 'Página anterior' })).toBeDisabled();
    expect(screen.getByText('1–25 de 287')).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Página siguiente' }));
    expect(onChange).toHaveBeenCalledWith(2);
  });
});

describe('DatePicker', () => {
  it('selects a date with the keyboard and returns focus to the trigger', async () => {
    const onChange = vi.fn();
    renderUi(<DatePicker label="Start date" defaultValue="2027-03-10" onChange={onChange} weekStartsOn={0} />);
    const trigger = screen.getByRole('button', { name: 'Choose date' });
    await userEvent.click(trigger);
    expect(screen.getByRole('dialog', { name: 'Choose date' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /March 10, 2027/ })).toHaveFocus();

    await userEvent.keyboard('{ArrowRight}');
    expect(screen.getByRole('button', { name: /March 11, 2027/ })).toHaveFocus();
    await userEvent.keyboard('{ArrowDown}');
    expect(screen.getByRole('button', { name: /March 18, 2027/ })).toHaveFocus();
    await userEvent.keyboard('{PageDown}');
    expect(screen.getByRole('button', { name: /April 18, 2027/ })).toHaveFocus();
    await userEvent.keyboard('{Enter}');

    expect(onChange).toHaveBeenCalledWith('2027-04-18');
    expect(screen.getByLabelText('Start date')).toHaveValue('2027-04-18');
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(trigger).toHaveFocus();
  });

  it('rejects invalid and out-of-range typed dates with an announced error', async () => {
    renderUi(<DatePicker label="End date" min="2027-01-01" />);
    const input = screen.getByLabelText('End date');
    await userEvent.type(input, '2027-02-30');
    await userEvent.tab();
    expect(input).toHaveAttribute('aria-invalid', 'true');
    expect(input).toHaveAccessibleDescription('Enter a valid date');
    await userEvent.clear(input);
    await userEvent.type(input, '2026-12-31{Enter}');
    expect(input).toHaveAttribute('aria-invalid', 'true');
  });
});

function ToastButton({ tone }: { tone: 'success' | 'danger' }) {
  const { toast } = useToast();
  return <button type="button" onClick={() => toast({ title: `${tone} message`, tone })}>notify {tone}</button>;
}

describe('Toast', () => {
  it('announces politely, errors assertively, and auto-dismisses non-errors', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderUi(<><ToastButton tone="success" /><ToastButton tone="danger" /></>);

    await user.click(screen.getByRole('button', { name: 'notify success' }));
    await user.click(screen.getByRole('button', { name: 'notify danger' }));
    const status = screen.getByRole('status');
    expect(status).toHaveTextContent('success message');
    expect(status.closest('[aria-live]')).toHaveAttribute('aria-live', 'polite');
    expect(screen.getByRole('alert').closest('[aria-live]')).toHaveAttribute('aria-live', 'assertive');

    act(() => { vi.advanceTimersByTime(6000); });
    expect(screen.queryByText('success message')).not.toBeInTheDocument();
    expect(screen.getByText('danger message')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Dismiss notification' }));
    expect(screen.queryByText('danger message')).not.toBeInTheDocument();
    vi.useRealTimers();
  });
});

describe('display components', () => {
  it('badge carries meaning in text; avatar falls back to initials with an accessible name', () => {
    renderUi(<><Badge tone="success" dot>Active</Badge><Avatar name="Claire Tremblay" /></>);
    expect(screen.getByText('Active')).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Claire Tremblay' })).toHaveTextContent('CT');
  });

  it('loading and error states use status and alert roles', () => {
    renderUi(<><LoadingState label="Loading people" /><ErrorState /></>);
    expect(screen.getByRole('status')).toHaveTextContent('Loading people');
    expect(screen.getByRole('alert')).toHaveTextContent('Something went wrong');
  });
});
