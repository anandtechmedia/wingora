import { useState, type ReactNode } from 'react';
import { breakpoints, contrastRatio, fontSize, layout, palette, radius, semanticColors, shadow, statusColors } from '@wingora/design-tokens';
import {
  Avatar, Badge, Button, Card, Checkbox, ConfirmationDialog, DataTable, DatePicker, Drawer, Dropdown, EmptyState, ErrorState, IconButton,
  Input, LoadingState, Modal, MoreIcon, Pagination, RadioGroup, SearchIcon, Select, Tabs, useToast, type SortState,
} from '@/components/ui';
import { PageHeader } from '@/shell/page-header';

/**
 * Living reference for the design system. Internal developer documentation: its copy is intentionally
 * not localized; product screens must use @wingora/localization.
 */
function Section({ id, title, description, children }: { id: string; title: string; description?: string; children: ReactNode }) {
  return (
    <section id={id} aria-labelledby={`${id}-title`} className="scroll-mt-24 border-t border-line py-10 first:border-t-0 first:pt-0">
      <h2 id={`${id}-title`} className="text-lg font-semibold tracking-tight text-fg">{title}</h2>
      {description && <p className="mt-1 max-w-(--container-prose) text-sm text-fg-secondary">{description}</p>}
      <div className="mt-6">{children}</div>
    </section>
  );
}

function Swatch({ name, value, on }: { name: string; value: string; on?: string }) {
  const ratio = value.startsWith('#') ? contrastRatio(on ?? '#FFFFFF', value) : null;
  return (
    <div className="overflow-hidden rounded-lg border border-line bg-surface">
      <div className="h-14" style={{ background: value }} />
      <div className="px-3 py-2">
        <p className="text-xs font-medium text-fg">{name}</p>
        <p className="font-mono text-2xs text-fg-muted">{value}{ratio ? ` · ${ratio.toFixed(1)}:1` : ''}</p>
      </div>
    </div>
  );
}

interface Person { id: string; name: string; role: string; location: string; status: 'Active' | 'On leave' | 'Onboarding'; started: string }
const people: Person[] = [
  { id: 'E-1001', name: 'Ana Ruiz', role: 'Payroll Specialist', location: 'Guadalajara, MX', status: 'Active', started: '2024-03-04' },
  { id: 'E-1002', name: 'Omar Haddad', role: 'HR Business Partner', location: 'Dubai, AE', status: 'On leave', started: '2023-09-18' },
  { id: 'E-1003', name: 'Claire Tremblay', role: 'Engineering Manager', location: 'Montréal, CA', status: 'Active', started: '2022-01-10' },
  { id: 'E-1004', name: 'Jordan Lee', role: 'Recruiter', location: 'Austin, US', status: 'Onboarding', started: '2026-11-02' },
  { id: 'E-1005', name: 'Priya Nair', role: 'Finance Analyst', location: 'Toronto, CA', status: 'Active', started: '2025-06-23' },
];
const tone = { Active: 'success', 'On leave': 'warning', Onboarding: 'info' } as const;

export function DesignSystemPage({ locale, onLocaleChange }: { locale: string; onLocaleChange: (locale: string) => void }) {
  const { toast } = useToast();
  const [modal, setModal] = useState(false);
  const [drawer, setDrawer] = useState(false);
  const [confirm, setConfirm] = useState(false);
  const [sort, setSort] = useState<SortState>({ columnId: 'name', direction: 'asc' });
  const [selected, setSelected] = useState<string[]>([]);
  const [page, setPage] = useState(1);
  const [tableState, setTableState] = useState<'data' | 'loading' | 'empty' | 'error'>('data');
  const [rtl, setRtl] = useState(false);

  const sorted = [...people].sort((a, b) => {
    const key = sort.columnId as keyof Person;
    return (sort.direction === 'asc' ? 1 : -1) * String(a[key]).localeCompare(String(b[key]));
  });

  const toggleRtl = () => {
    document.documentElement.dir = rtl ? 'ltr' : 'rtl';
    setRtl(!rtl);
  };

  return (
    <>
      <PageHeader eyebrow="Wingora Design System" title="People. Progress. Performance."
        description="Tokens, components and layouts for Wingora web. Global HR. Local Intelligence."
        actions={(
          <>
            <Select label="Preview locale" hideLabel value={locale} onChange={(e) => onLocaleChange(e.target.value)}
              options={[{ value: 'en-US', label: 'English (US)' }, { value: 'es-MX', label: 'Español (MX)' }, { value: 'fr-CA', label: 'Français (CA) · planned' }, { value: 'ar-AE', label: 'العربية · planned' }]} />
            <Button variant="secondary" onClick={toggleRtl} aria-pressed={rtl}>{rtl ? 'LTR preview' : 'RTL preview'}</Button>
          </>
        )} />

      <nav aria-label="Design system sections" className="mb-10 flex flex-wrap gap-2">
        {['colors', 'typography', 'spacing', 'elevation', 'layout', 'buttons', 'forms', 'data', 'overlays', 'feedback', 'navigation'].map((s) => (
          <a key={s} href={`#${s}`} className="rounded-full border border-line bg-surface px-3 py-1 text-xs font-medium text-fg-secondary capitalize hover:border-line-strong hover:text-fg">{s}</a>
        ))}
      </nav>

      <Section id="colors" title="Colour" description="Neutral surfaces carry the interface; navy is the brand and primary action colour, indigo is the restrained accent for focus, links and selection. Ratios are against white text or shown pairs.">
        <h3 className="mb-3 text-sm font-semibold text-fg">Brand and neutrals</h3>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
          {(['navy', 'indigo', 'neutral'] as const).flatMap((name) => [900, 700, 500, 300, 100, 50].map((step) => (
            <Swatch key={`${name}-${step}`} name={`${name}-${step}`} value={palette[name][step as keyof (typeof palette)[typeof name]]} />
          )))}
        </div>
        <h3 className="mt-8 mb-3 text-sm font-semibold text-fg">Semantic</h3>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
          {(['canvas', 'surface', 'surface-muted', 'fg', 'fg-secondary', 'fg-muted', 'line', 'line-control', 'primary', 'accent', 'focus', 'nav-bg'] as const).map((name) => (
            <Swatch key={name} name={name} value={semanticColors[name]} />
          ))}
        </div>
        <h3 className="mt-8 mb-3 text-sm font-semibold text-fg">Status</h3>
        <div className="flex flex-wrap gap-2">
          {(Object.keys(statusColors) as Array<keyof typeof statusColors>).map((s) => (
            <span key={s} className="flex gap-2">
              <Badge tone={s} dot>{s}</Badge>
              <Badge tone={s} variant="solid">{s}</Badge>
              <Badge tone={s} variant="outline">{s}</Badge>
            </span>
          ))}
        </div>
      </Section>

      <Section id="typography" title="Typography" description="System UI stack with optional Inter. 14px is the default interface size; headings use tight tracking.">
        <div className="flex flex-col divide-y divide-line rounded-lg border border-line bg-surface">
          {(Object.entries(fontSize) as Array<[string, { size: string; lineHeight: string }]>).reverse().map(([name, v]) => (
            <div key={name} className="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:items-baseline sm:gap-6">
              <span className="w-28 shrink-0 font-mono text-2xs text-fg-muted">text-{name} · {v.size}</span>
              <span className="truncate font-semibold tracking-tight text-fg" style={{ fontSize: v.size, lineHeight: v.lineHeight }}>Global HR. Local Intelligence.</span>
            </div>
          ))}
        </div>
      </Section>

      <Section id="spacing" title="Spacing and radius" description="4px grid. Components use 8–24px internally; page sections are separated by 32–40px.">
        <div className="grid gap-6 lg:grid-cols-2">
          <div className="flex flex-col gap-2">
            {[1, 2, 3, 4, 6, 8, 10, 12, 16].map((n) => (
              <div key={n} className="flex items-center gap-3">
                <span className="w-16 font-mono text-2xs text-fg-muted">{n} · {n * 4}px</span>
                <span className="h-3 rounded-sm bg-accent" style={{ width: `${n * 4}px` }} />
              </div>
            ))}
          </div>
          <div className="grid grid-cols-3 gap-3 sm:grid-cols-4">
            {Object.entries(radius).map(([name, value]) => (
              <div key={name} className="flex flex-col items-center gap-2">
                <span className="size-14 border border-line-strong bg-surface-muted" style={{ borderRadius: value }} />
                <span className="font-mono text-2xs text-fg-muted">{name}</span>
              </div>
            ))}
          </div>
        </div>
      </Section>

      <Section id="elevation" title="Elevation" description="Shadows are soft and used sparingly: cards xs, menus and toasts lg, dialogs xl.">
        <div className="grid grid-cols-2 gap-6 sm:grid-cols-5">
          {Object.entries(shadow).map(([name, value]) => (
            <div key={name} className="flex h-20 items-center justify-center rounded-lg bg-surface text-xs font-medium text-fg-secondary" style={{ boxShadow: value }}>shadow-{name}</div>
          ))}
        </div>
      </Section>

      <Section id="layout" title="Layout and breakpoints" description="Mobile < 768px, tablet 768–1023px, desktop ≥ 1024px. Pages support 360px width.">
        <div className="grid gap-4 md:grid-cols-2">
          <Card title="Breakpoints">
            <dl className="grid grid-cols-2 gap-y-1 font-mono text-xs">
              {Object.entries(breakpoints).map(([k, v]) => <div key={k} className="contents"><dt className="text-fg-muted">{k}</dt><dd className="text-fg">{v} · {parseFloat(v) * 16}px</dd></div>)}
            </dl>
          </Card>
          <Card title="Layout widths">
            <dl className="grid grid-cols-2 gap-y-1 font-mono text-xs">
              {Object.entries(layout).slice(0, 8).map(([k, v]) => <div key={k} className="contents"><dt className="text-fg-muted">{k}</dt><dd className="text-fg">{v}</dd></div>)}
            </dl>
          </Card>
        </div>
      </Section>

      <Section id="buttons" title="Buttons" description="One primary action per view. Destructive actions use danger and always confirm.">
        <div className="flex flex-wrap items-center gap-3">
          <Button>Save changes</Button>
          <Button variant="secondary">Cancel</Button>
          <Button variant="tertiary">View history</Button>
          <Button variant="danger">Delete</Button>
          <Button variant="link">Learn more</Button>
          <Button loading>Saving</Button>
          <Button disabled>Disabled</Button>
          <IconButton label="More actions" icon={<MoreIcon />} variant="secondary" />
        </div>
        <div className="mt-4 flex flex-wrap items-center gap-3">
          <Button size="sm">Small</Button>
          <Button size="md">Medium</Button>
          <Button size="lg">Large · touch</Button>
        </div>
      </Section>

      <Section id="forms" title="Form controls" description="Every control has a visible label, optional hint and an error that is announced and not colour-only.">
        <Card>
          <form className="grid gap-5 md:grid-cols-2" onSubmit={(e) => e.preventDefault()} noValidate>
            <Input label="Legal first name" required autoComplete="given-name" defaultValue="Ana" />
            <Input label="Work email" type="email" hint="Used for sign-in and notifications." placeholder="name@company.com" />
            <Input label="Employee number" error="This employee number is already in use." defaultValue="E-1001" />
            <Input label="Search people" hideLabel leading={<SearchIcon />} placeholder="Search people" />
            <Select label="Work location" options={[{ value: 'gdl', label: 'Guadalajara, MX' }, { value: 'dxb', label: 'Dubai, AE' }, { value: 'yul', label: 'Montréal, CA' }]} placeholder="Select a location" />
            <DatePicker label="Start date" defaultValue="2026-11-16" min="2026-01-01" />
            <RadioGroup label="Employment type" defaultValue="full_time" orientation="horizontal"
              options={[{ value: 'full_time', label: 'Full-time' }, { value: 'part_time', label: 'Part-time' }, { value: 'contractor', label: 'Fixed-term', description: 'Requires an end date' }]} />
            <div className="flex flex-col gap-3">
              <Checkbox label="Send welcome email" description="Delivered on the start date in the employee's language." defaultChecked />
              <Checkbox label="Requires work permit" />
              <Checkbox label="Disabled option" disabled />
            </div>
            <div className="flex justify-end gap-2 md:col-span-2">
              <Button variant="secondary">Cancel</Button>
              <Button type="submit">Save employee</Button>
            </div>
          </form>
        </Card>
      </Section>

      <Section id="data" title="Data display" description="Tables sort and paginate on the server. Below 768px, rows become cards.">
        <div className="mb-4 flex flex-wrap gap-2" role="group" aria-label="Table state">
          {(['data', 'loading', 'empty', 'error'] as const).map((s) => (
            <Button key={s} size="sm" variant={tableState === s ? 'primary' : 'secondary'} aria-pressed={tableState === s} onClick={() => setTableState(s)}>{s}</Button>
          ))}
        </div>
        <DataTable caption="People" columns={[
          { id: 'name', header: 'Name', sortable: true, primary: true, cell: (p) => (
            <span className="flex items-center gap-3"><Avatar name={p.name} size="sm" decorative /><span className="flex flex-col"><span className="font-medium">{p.name}</span><span className="text-xs text-fg-muted">{p.id}</span></span></span>
          ) },
          { id: 'role', header: 'Role', sortable: true, cell: (p) => p.role },
          { id: 'location', header: 'Location', hideBelow: 'lg', cell: (p) => p.location },
          { id: 'status', header: 'Status', cell: (p) => <Badge tone={tone[p.status]} dot>{p.status}</Badge> },
          { id: 'started', header: 'Start date', sortable: true, align: 'end', cell: (p) => <span className="tabular-nums">{new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(p.started))}</span> },
          { id: 'actions', header: <span className="sr-only">Actions</span>, align: 'end', cell: (p) => (
            <Dropdown variant="tertiary" size="sm" label={`Actions for ${p.name}`} trigger={<MoreIcon />}
              items={[{ id: 'view', label: 'View profile', onSelect: () => setDrawer(true) }, { id: 'edit', label: 'Edit', onSelect: () => setModal(true) }, { type: 'separator', id: 's' }, { id: 'delete', label: 'Delete', tone: 'danger', onSelect: () => setConfirm(true) }]} />
          ) },
        ]} rows={tableState === 'empty' ? [] : sorted} getRowId={(p) => p.id} sort={sort} onSortChange={setSort} selectable selectedIds={selected} onSelectionChange={setSelected}
          loading={tableState === 'loading'} error={tableState === 'error' ? 'The people directory could not be loaded.' : null} onRetry={() => setTableState('data')}
          empty={<EmptyState title="No people match these filters" description="Try removing a filter or searching by employee number." action={<Button variant="secondary" onClick={() => setTableState('data')}>Clear filters</Button>} />}
          footer={<Pagination page={page} pageCount={12} onPageChange={setPage} total={287} pageSize={25} />} />

        <div className="mt-8 grid gap-4 md:grid-cols-3">
          <Card title="Headcount" description="All legal entities"><p className="text-3xl font-semibold tracking-tight tabular-nums">{new Intl.NumberFormat(locale).format(1284)}</p></Card>
          <Card title="Avatars"><div className="flex items-center gap-2">{['Ana Ruiz', 'Omar Haddad', 'Claire Tremblay', 'Jordan Lee'].map((n, i) => <Avatar key={n} name={n} size={(['xs', 'sm', 'md', 'lg'] as const)[i]} />)}</div></Card>
          <Card title="Tabs" padding="md">
            <Tabs label="Profile sections" tabs={[
              { id: 'overview', label: 'Overview', content: <p className="text-sm text-fg-secondary">Personal and employment details.</p> },
              { id: 'documents', label: 'Documents', badge: <Badge size="sm">3</Badge>, content: <p className="text-sm text-fg-secondary">Contracts and identity documents.</p> },
              { id: 'history', label: 'History', content: <p className="text-sm text-fg-secondary">Employment record changes.</p> },
            ]} />
          </Card>
        </div>
      </Section>

      <Section id="overlays" title="Overlays" description="Modal and Drawer trap focus, close on Escape and return focus to the trigger.">
        <div className="flex flex-wrap gap-3">
          <Button variant="secondary" onClick={() => setModal(true)}>Open modal</Button>
          <Button variant="secondary" onClick={() => setDrawer(true)}>Open drawer</Button>
          <Button variant="danger" onClick={() => setConfirm(true)}>Delete record…</Button>
          <Dropdown trigger="Actions" items={[{ id: 'a', label: 'Export', onSelect: () => toast({ title: 'Export started', tone: 'info' }) }, { id: 'b', label: 'Archive', onSelect: () => undefined }]} />
        </div>
        <Modal open={modal} onClose={() => setModal(false)} title="Edit employment details" description="Changes are recorded in the employment history."
          footer={<><Button variant="secondary" onClick={() => setModal(false)}>Cancel</Button><Button onClick={() => { setModal(false); toast({ title: 'Changes saved', tone: 'success' }); }}>Save</Button></>}>
          <div className="grid gap-4"><Input label="Job title" defaultValue="Payroll Specialist" /><DatePicker label="Effective date" defaultValue="2026-12-01" /></div>
        </Modal>
        <Drawer open={drawer} onClose={() => setDrawer(false)} title="Ana Ruiz" description="Payroll Specialist · Guadalajara, MX"
          footer={<Button variant="secondary" onClick={() => setDrawer(false)}>Close</Button>}>
          <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-3 text-sm"><dt className="text-fg-muted">Employee</dt><dd>E-1001</dd><dt className="text-fg-muted">Status</dt><dd><Badge tone="success" dot>Active</Badge></dd></dl>
        </Drawer>
        <ConfirmationDialog open={confirm} onCancel={() => setConfirm(false)} title="Delete this record?" description="This cannot be undone. The deletion is recorded in the audit log."
          onConfirm={async () => { await new Promise((r) => setTimeout(r, 600)); setConfirm(false); toast({ title: 'Record deleted', tone: 'success' }); }} />
      </Section>

      <Section id="feedback" title="Feedback and states" description="Every list has loading, empty and error states. Toasts announce through live regions.">
        <div className="mb-6 flex flex-wrap gap-3">
          <Button variant="secondary" onClick={() => toast({ title: 'Leave request approved', description: 'Ana Ruiz · 2 days', tone: 'success' })}>Success toast</Button>
          <Button variant="secondary" onClick={() => toast({ title: 'Policy update pending', tone: 'warning' })}>Warning toast</Button>
          <Button variant="secondary" onClick={() => toast({ title: 'Sync failed', description: 'The ERP did not respond.', tone: 'danger', action: { label: 'Retry', onClick: () => undefined } })}>Error toast</Button>
        </div>
        <div className="grid gap-4 md:grid-cols-3">
          <Card padding="none"><EmptyState title="No documents" description="Uploaded documents appear here." /></Card>
          <Card padding="none"><LoadingState /></Card>
          <Card padding="none"><ErrorState onRetry={() => undefined} /></Card>
        </div>
      </Section>

      <Section id="navigation" title="Navigation" description="Sidebar grouped by product area; icon rail on tablet; drawer on mobile. Resize the window to see each layout.">
        <p className="text-sm text-fg-secondary">The application frame around this page is the production <code className="rounded-sm bg-surface-muted px-1 font-mono text-xs">AppLayout</code>.</p>
      </Section>
    </>
  );
}
