import { useState, type FormEvent } from 'react';
import type { DirectoryOptions, EmployeeDetail, EmployeeInput, EmployeeStatus } from '@/api/employees';
import { useEmployeeSource } from '@/api/employees';
import { Button, DatePicker, Input, Modal, Select, useToast } from '@/components/ui';
import { useI18n } from '@/i18n/i18n';
import { errorMessage, validationErrors } from './shared';

const STATUSES: EmployeeStatus[] = ['active', 'on_leave', 'suspended', 'terminated'];

/** Create (no `employee`) or edit employment details. The server validates every reference and country rule. */
export function EmployeeForm({ open, onClose, onSaved, employee, options }: {
  open: boolean; onClose: () => void; onSaved: (e: EmployeeDetail) => void; employee?: EmployeeDetail; options: DirectoryOptions;
}) {
  const { t } = useI18n();
  const source = useEmployeeSource();
  const { toast } = useToast();
  const initial: EmployeeInput = {
    employee_number: employee?.employee_number ?? '', first_name: employee?.first_name ?? '', last_name: employee?.last_name ?? '',
    preferred_name: employee?.preferred_name ?? '', work_email: employee?.work_email ?? '', job_title: employee?.job_title ?? '',
    status: employee?.status ?? 'active', employment_type: employee?.employment_type ?? '', hire_date: employee?.hire_date ?? '',
    department_id: employee?.department?.id ?? '', designation_id: employee?.designation?.id ?? '', manager_id: employee?.manager?.id ?? '',
    legal_entity_id: employee?.legal_entity?.id ?? '', work_location_id: employee?.work_location?.id ?? '', effective_date: '', note: '',
  };
  const [values, setValues] = useState(initial);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const set = (key: keyof EmployeeInput) => (value: string) => setValues((v) => ({ ...v, [key]: value }));
  const field = (key: keyof EmployeeInput) => ({ value: (values[key] as string) ?? '', error: errors[key], onChange: (e: { target: { value: string } }) => set(key)(e.target.value) });
  const locations = options.workLocations.filter((l) => !values.legal_entity_id || l.legal_entity_id === values.legal_entity_id);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setSaving(true);
    setErrors({});
    const payload = Object.fromEntries(Object.entries(values)
      .filter(([k, v]) => !employee || v !== (initial as Record<string, unknown>)[k] || k === 'effective_date')
      .map(([k, v]) => [k, v === '' ? null : v])
      .filter(([k, v]) => !(employee && k === 'effective_date' && v === null))) as EmployeeInput;
    try {
      const saved = employee ? await source.update(employee.id, payload) : await source.create(payload);
      toast({ title: t(employee ? 'employees.saved' : 'employees.created'), tone: 'success' });
      onSaved(saved);
    } catch (error) {
      setErrors(validationErrors(error));
      toast({ title: errorMessage(error, t('employees.saveFailed')), tone: 'danger' });
    } finally {
      setSaving(false);
    }
  }

  const opts = (list: { id: string; name: string }[]) => list.map((o) => ({ value: o.id, label: o.name }));
  return (
    <Modal open={open} onClose={onClose} size="lg" title={t(employee ? 'employees.editTitle' : 'employees.createTitle')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button type="submit" form="employee-form" loading={saving}>{t('employees.save')}</Button></>}>
      <form id="employee-form" onSubmit={submit} noValidate className="grid gap-4 sm:grid-cols-2">
        <Input label={t('employees.number')} required {...field('employee_number')} />
        <Input label={t('employees.workEmail')} type="email" {...field('work_email')} />
        <Input label={t('employees.firstName')} required {...field('first_name')} />
        <Input label={t('employees.lastName')} required {...field('last_name')} />
        <Input label={t('employees.preferredName')} {...field('preferred_name')} />
        <Input label={t('employees.jobTitle')} {...field('job_title')} />
        <Select label={t('employees.department')} placeholder={t('employees.none')} options={opts(options.departments)} {...field('department_id')} />
        <Select label={t('employees.designation')} placeholder={t('employees.none')} options={opts(options.designations)} {...field('designation_id')} />
        <Select label={t('employees.legalEntity')} placeholder={t('employees.none')} options={opts(options.legalEntities)} {...field('legal_entity_id')}
          onChange={(e) => setValues((v) => ({ ...v, legal_entity_id: e.target.value, work_location_id: '' }))} />
        <Select label={t('employees.workLocation')} placeholder={t('employees.none')} options={opts(locations)} {...field('work_location_id')} />
        <Input label={t('employees.employmentType')} hint="full_time, part_time…" {...field('employment_type')} />
        <Select label={t('employees.status')} options={STATUSES.map((s) => ({ value: s, label: t(`employees.status.${s}`) }))} {...field('status')} />
        <Input label={t('employees.manager')} hint={t('employees.managerHint')} {...field('manager_id')} />
        <DatePicker label={t('employees.hireDate')} value={values.hire_date ?? ''} onChange={set('hire_date')} error={errors.hire_date} />
        {employee && <DatePicker label={t('employees.effectiveDate')} value={values.effective_date ?? ''} onChange={set('effective_date')} error={errors.effective_date} />}
        {employee && <Input label={t('employees.note')} {...field('note')} />}
      </form>
    </Modal>
  );
}
