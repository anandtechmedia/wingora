import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { renderUi } from '@/test/render';
import { axeViolations } from '@/test/axe';
import { Button, Checkbox, IconButton, Input, RadioGroup, Select } from '.';
import { XIcon } from './icons';

describe('Button', () => {
  it('defaults to type=button and exposes busy state while loading', () => {
    renderUi(<><Button>Save</Button><Button loading>Saving</Button></>);
    expect(screen.getByRole('button', { name: 'Save' })).toHaveAttribute('type', 'button');
    const busy = screen.getByRole('button', { name: 'Saving' });
    expect(busy).toBeDisabled();
    expect(busy).toHaveAttribute('aria-busy', 'true');
  });

  it('icon buttons require and expose an accessible name', async () => {
    const onClick = vi.fn();
    renderUi(<IconButton label="Close panel" icon={<XIcon />} onClick={onClick} />);
    await userEvent.click(screen.getByRole('button', { name: 'Close panel' }));
    expect(onClick).toHaveBeenCalledOnce();
  });
});

describe('form controls', () => {
  it('associates label, hint and error and marks invalid state', async () => {
    const { container } = renderUi(
      <form>
        <Input label="Work email" hint="Used for sign-in" required />
        <Input label="Employee number" error="Already in use" />
        <Select label="Location" options={[{ value: 'gdl', label: 'Guadalajara' }]} />
        <Checkbox label="Send welcome email" description="On the start date" />
        <RadioGroup label="Type" options={[{ value: 'a', label: 'Full-time' }, { value: 'b', label: 'Part-time' }]} />
      </form>,
    );
    const email = screen.getByLabelText(/Work email/);
    expect(email).toBeRequired();
    expect(email).toHaveAccessibleDescription('Used for sign-in');
    const number = screen.getByLabelText('Employee number');
    expect(number).toHaveAttribute('aria-invalid', 'true');
    expect(number).toHaveAccessibleDescription('Already in use');
    expect(screen.getByRole('checkbox', { name: 'Send welcome email' })).toHaveAccessibleDescription('On the start date');
    expect(screen.getByRole('group', { name: 'Type' })).toBeInTheDocument();
    expect(await axeViolations(container)).toEqual([]);
  });

  it('checkbox supports the indeterminate state and keyboard toggling', async () => {
    renderUi(<Checkbox label="Select all" indeterminate />);
    const box = screen.getByRole('checkbox', { name: 'Select all' }) as HTMLInputElement;
    expect(box.indeterminate).toBe(true);
    box.focus();
    await userEvent.keyboard(' ');
    expect(box).toBeChecked();
  });

  it('radio group reports the selected value', async () => {
    const onChange = vi.fn();
    renderUi(<RadioGroup label="Type" onChange={onChange} options={[{ value: 'a', label: 'Full-time' }, { value: 'b', label: 'Part-time' }]} />);
    await userEvent.click(screen.getByLabelText('Part-time'));
    expect(onChange).toHaveBeenCalledWith('b');
    expect(screen.getByLabelText('Part-time')).toBeChecked();
  });
});
