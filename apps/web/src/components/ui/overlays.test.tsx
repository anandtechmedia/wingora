import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { renderUi } from '@/test/render';
import { Button, ConfirmationDialog, Drawer, Dropdown, Modal, Tabs } from '.';

function ModalHarness() {
  const [open, setOpen] = useState(false);
  return (
    <>
      <Button onClick={() => setOpen(true)}>Open</Button>
      <Modal open={open} onClose={() => setOpen(false)} title="Edit details" description="Changes are audited"
        footer={<><Button onClick={() => setOpen(false)}>Cancel</Button><Button>Save</Button></>}>
        <input aria-label="Job title" />
      </Modal>
    </>
  );
}

describe('Modal', () => {
  it('is a labelled modal dialog that traps focus, closes on Escape and restores focus', async () => {
    renderUi(<ModalHarness />);
    const trigger = screen.getByRole('button', { name: 'Open' });
    await userEvent.click(trigger);

    const dialog = screen.getByRole('dialog', { name: 'Edit details' });
    expect(dialog).toHaveAttribute('aria-modal', 'true');
    expect(dialog).toHaveAccessibleDescription('Changes are audited');
    expect(screen.getByRole('button', { name: 'Close' })).toHaveFocus();

    await userEvent.tab();
    expect(screen.getByLabelText('Job title')).toHaveFocus();
    await userEvent.tab();
    await userEvent.tab();
    expect(screen.getByRole('button', { name: 'Save' })).toHaveFocus();
    await userEvent.tab();
    expect(screen.getByRole('button', { name: 'Close' })).toHaveFocus();
    await userEvent.tab({ shift: true });
    expect(screen.getByRole('button', { name: 'Save' })).toHaveFocus();

    await userEvent.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(trigger).toHaveFocus();
  });
});

describe('Drawer', () => {
  it('opens as a dialog and closes from its close button', async () => {
    const onClose = vi.fn();
    renderUi(<Drawer open onClose={onClose} title="Ana Ruiz">Details</Drawer>);
    expect(screen.getByRole('dialog', { name: 'Ana Ruiz' })).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Close' }));
    expect(onClose).toHaveBeenCalled();
  });
});

describe('ConfirmationDialog', () => {
  it('is an alertdialog that focuses Cancel first for destructive actions and awaits confirmation', async () => {
    let resolve!: () => void;
    const onConfirm = vi.fn(() => new Promise<void>((r) => { resolve = r; }));
    renderUi(<ConfirmationDialog open title="Delete record?" description="This cannot be undone." onConfirm={onConfirm} onCancel={() => undefined} />);

    expect(screen.getByRole('alertdialog', { name: 'Delete record?' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Cancel' })).toHaveFocus();
    await userEvent.click(screen.getByRole('button', { name: 'Delete' }));
    expect(onConfirm).toHaveBeenCalledOnce();
    expect(screen.getByRole('button', { name: 'Delete' })).toHaveAttribute('aria-busy', 'true');
    resolve();
    await waitFor(() => expect(screen.getByRole('button', { name: 'Delete' })).not.toHaveAttribute('aria-busy'));
  });
});

describe('Tabs', () => {
  it('moves between tabs with arrow keys, Home and End', async () => {
    renderUi(<Tabs label="Profile" tabs={[
      { id: 'a', label: 'Overview', content: 'Overview panel' },
      { id: 'b', label: 'Documents', content: 'Documents panel' },
      { id: 'c', label: 'History', content: 'History panel' },
    ]} />);
    const first = screen.getByRole('tab', { name: 'Overview' });
    expect(first).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByRole('tabpanel', { name: 'Overview' })).toHaveTextContent('Overview panel');

    first.focus();
    await userEvent.keyboard('{ArrowRight}');
    expect(screen.getByRole('tab', { name: 'Documents' })).toHaveFocus();
    expect(screen.getByRole('tab', { name: 'Documents' })).toHaveAttribute('aria-selected', 'true');
    await userEvent.keyboard('{End}');
    expect(screen.getByRole('tab', { name: 'History' })).toHaveFocus();
    await userEvent.keyboard('{ArrowRight}');
    expect(screen.getByRole('tab', { name: 'Overview' })).toHaveFocus();
    expect(screen.getByRole('tab', { name: 'History' })).toHaveAttribute('tabindex', '-1');
  });
});

describe('Dropdown', () => {
  it('opens from the keyboard, supports arrows and type-ahead, and returns focus on Escape', async () => {
    const onExport = vi.fn();
    renderUi(<Dropdown trigger="Actions" items={[
      { id: 'e', label: 'Export', onSelect: onExport },
      { id: 'a', label: 'Archive', onSelect: () => undefined },
      { id: 'd', label: 'Delete', onSelect: () => undefined, tone: 'danger' },
    ]} />);
    const trigger = screen.getByRole('button', { name: 'Actions' });
    expect(trigger).toHaveAttribute('aria-haspopup', 'menu');

    trigger.focus();
    await userEvent.keyboard('{ArrowDown}');
    expect(trigger).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getByRole('menuitem', { name: 'Export' })).toHaveFocus();
    await userEvent.keyboard('{ArrowDown}');
    expect(screen.getByRole('menuitem', { name: 'Archive' })).toHaveFocus();
    await userEvent.keyboard('d');
    expect(screen.getByRole('menuitem', { name: 'Delete' })).toHaveFocus();
    await userEvent.keyboard('{Escape}');
    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
    expect(trigger).toHaveFocus();

    await userEvent.keyboard('{Enter}');
    await userEvent.keyboard('{Enter}');
    expect(onExport).toHaveBeenCalledOnce();
  });
});
