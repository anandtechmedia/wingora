import { useCallback, useState } from 'react';
import { Link, useParams } from 'react-router';
import type { MessageKey } from '@wingora/localization';
import { ApiNotConnectedError } from '@/api/dashboard';
import { ApiError } from '@/api/http';
import { useCareersSource, type ApplyInput, type CareersOpening, type CareersSite } from '@/api/recruitment';
import { Badge, Button, Card, Checkbox, EmptyState, ErrorState, Input, LoadingState } from '@/components/ui';
import { useI18n } from '@/i18n/i18n';
import { FormError, formError, useResource, validationErrors } from '@/modules/employees/shared';
import { TextArea } from '@/modules/time/shared';
import { FileField } from '@/modules/payroll/shared';

/** Public careers site at /careers/:site — no sign-in; the organization comes only from its careers address. */
export default function CareersPage() {
  const { site = '', job } = useParams();
  const { t } = useI18n();
  const ct = (k: string, p?: Record<string, string | number>) => t(`careers.${k}` as MessageKey, p);
  const source = useCareersSource();
  const data = useResource(useCallback((signal: AbortSignal) => source.site(site, signal), [source, site]));
  return (
    <div className="min-h-screen bg-canvas">
      <header className="border-b border-line bg-surface">
        <div className="mx-auto flex max-w-4xl items-center justify-between gap-4 px-4 py-4">
          <Link to={`/careers/${site}`} className="text-lg font-semibold text-fg">{data.data?.organization ?? ct('title')}</Link>
          <span className="text-xs text-fg-muted">{ct('poweredBy')}</span>
        </div>
      </header>
      <main id="main" className="mx-auto max-w-4xl px-4 py-8">
        {data.loading && !data.data ? <LoadingState variant="skeleton" rows={4} />
          : data.error instanceof ApiNotConnectedError ? <EmptyState title={ct('notConnected')} />
            : data.error instanceof ApiError && data.error.status === 404 ? <EmptyState title={ct('notFound')} description={ct('notFoundHint')} />
              : data.error ? <ErrorState description={ct('loadError')} onRetry={data.reload} />
                : data.data && (job ? <JobView site={site} slug={job} info={data.data} ct={ct} /> : <Listing site={site} info={data.data} ct={ct} />)}
      </main>
    </div>
  );
}

type Ct = (k: string, p?: Record<string, string | number>) => string;

function Listing({ site, info, ct }: { site: string; info: CareersSite; ct: Ct }) {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-fg">{info.headline || ct('headlineFallback', { organization: info.organization })}</h1>
        {info.intro && <p className="mt-2 whitespace-pre-line text-fg-muted">{info.intro}</p>}
      </div>
      {info.openings.length === 0 ? <EmptyState title={ct('noOpenings')} /> : (
        <ul className="grid gap-3" aria-label={ct('openings')}>
          {info.openings.map((o) => (
            <li key={o.slug}>
              <Link to={`/careers/${site}/${o.slug}`} className="block rounded-lg border border-line bg-surface p-4 hover:border-primary focus-visible:outline-2 focus-visible:outline-focus">
                <div className="font-medium text-fg">{o.title}</div>
                <Meta o={o} ct={ct} />
                {o.summary && <p className="mt-2 text-sm text-fg-muted">{o.summary}</p>}
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function Meta({ o, ct }: { o: CareersOpening; ct: Ct }) {
  return (
    <div className="mt-1 flex flex-wrap gap-2 text-sm text-fg-muted">
      {o.department && <span>{o.department}</span>}
      {o.location && <span>{o.location}</span>}
      <Badge size="sm" variant="outline">{ct(`workplace.${o.workplace}`)}</Badge>
      {o.closes_on && <span>{ct('closesOn', { date: o.closes_on })}</span>}
    </div>
  );
}

function JobView({ site, slug, info, ct }: { site: string; slug: string; info: CareersSite; ct: Ct }) {
  const source = useCareersSource();
  const job = useResource(useCallback((signal: AbortSignal) => source.opening(site, slug, signal), [source, site, slug]));
  if (job.loading && !job.data) return <LoadingState variant="skeleton" rows={4} />;
  if (job.error || !job.data) return <EmptyState title={ct('jobNotFound')} action={<Link className="text-primary underline" to={`/careers/${site}`}>{ct('allOpenings')}</Link>} />;
  const o = job.data;
  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_22rem]">
      <article className="min-w-0">
        <Link className="text-sm text-primary underline" to={`/careers/${site}`}>{ct('allOpenings')}</Link>
        <h1 className="mt-2 text-2xl font-semibold text-fg">{o.title}</h1>
        <Meta o={o} ct={ct} />
        {o.description && <section className="mt-6"><h2 className="text-lg font-medium text-fg">{ct('aboutRole')}</h2><p className="mt-2 whitespace-pre-line text-fg">{o.description}</p></section>}
        {o.requirements && <section className="mt-6"><h2 className="text-lg font-medium text-fg">{ct('requirements')}</h2><p className="mt-2 whitespace-pre-line text-fg">{o.requirements}</p></section>}
      </article>
      {o.open ? <ApplyForm site={site} slug={slug} info={info} ct={ct} /> : <Card><p className="text-sm text-fg-muted">{ct('closed')}</p></Card>}
    </div>
  );
}

const blank: ApplyInput = { first_name: '', last_name: '', email: '', phone: '', location: '', linkedin_url: '', cover_letter: '', resume: null, consent: false, consent_version: '', website: '' };

function ApplyForm({ site, slug, info, ct }: { site: string; slug: string; info: CareersSite; ct: Ct }) {
  const source = useCareersSource();
  const [input, setInput] = useState<ApplyInput>({ ...blank, consent_version: info.consent.version });
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState(false);
  const set = (k: keyof ApplyInput) => (v: string) => setInput((s) => ({ ...s, [k]: v }));
  const fields = validationErrors(error);
  if (sent) return <Card title={ct('thanksTitle')} headingLevel={2}><p role="status" className="text-sm text-fg">{ct('thanks')}</p></Card>;
  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await source.apply(site, slug, input);
      setSent(true);
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  };
  const stale = error instanceof ApiError && error.status === 409;
  const limited = error instanceof ApiError && error.status === 429;
  return (
    <Card title={ct('apply')} headingLevel={2}>
      <form className="grid gap-3" onSubmit={submit} noValidate>
        <Input label={ct('firstName')} required autoComplete="given-name" value={input.first_name} onChange={(e) => set('first_name')(e.target.value)} error={fields.first_name} />
        <Input label={ct('lastName')} required autoComplete="family-name" value={input.last_name} onChange={(e) => set('last_name')(e.target.value)} error={fields.last_name} />
        <Input label={ct('email')} required type="email" autoComplete="email" value={input.email} onChange={(e) => set('email')(e.target.value)} error={fields.email} />
        <Input label={ct('phone')} type="tel" autoComplete="tel" value={input.phone} onChange={(e) => set('phone')(e.target.value)} error={fields.phone} />
        <Input label={ct('location')} value={input.location} onChange={(e) => set('location')(e.target.value)} error={fields.location} />
        <Input label={ct('linkedin')} type="url" value={input.linkedin_url} onChange={(e) => set('linkedin_url')(e.target.value)} error={fields.linkedin_url} />
        <TextArea label={ct('coverLetter')} value={input.cover_letter} onChange={set('cover_letter')} error={fields.cover_letter} />
        <FileField label={ct('resume')} accept=".pdf,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document" onFile={(f) => setInput((s) => ({ ...s, resume: f }))} />
        {fields.resume && <p className="text-xs text-danger-fg">{fields.resume}</p>}
        <div aria-hidden="true" className="absolute -left-[9999px] h-0 w-0 overflow-hidden">
          <label>Website<input tabIndex={-1} autoComplete="off" value={input.website} onChange={(e) => set('website')(e.target.value)} /></label>
        </div>
        <div className="rounded-md border border-line bg-canvas p-3 text-xs text-fg-muted whitespace-pre-line">{info.consent.text}</div>
        <Checkbox label={ct('consent', { days: info.consent.retention_days })} checked={input.consent} onChange={(e) => setInput((s) => ({ ...s, consent: e.target.checked }))} />
        {fields.consent && <p className="text-xs text-danger-fg">{fields.consent}</p>}
        <FormError message={stale ? ct('staleConsent') : limited ? ct('tooMany') : Object.keys(fields).length ? null : error ? formError(error, ct('applyFailed')) : null} />
        <Button type="submit" loading={busy} disabled={!input.consent || !input.resume}>{ct('submit')}</Button>
      </form>
    </Card>
  );
}
