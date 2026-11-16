import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Link, useLocation, useNavigate, useParams, useSearchParams } from 'react-router';
import { Button, IconButton, Input, LoadingState } from '@/components/ui';
import { AuthError, type OrgSignIn } from '@/auth/types';
import { safeRedirect, useSession } from '@/auth/session';
import { useI18n } from '@/i18n/i18n';
import { AuthLayout, FormAlert, type AuthBrand } from './auth-layout';

const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const slugPattern = /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/;

type OrgState = { status: 'none' } | { status: 'loading' } | { status: 'missing' } | { status: 'ready'; org: OrgSignIn };

/** `/login` (any organization) and `/login/:org` (an organization's branded page with single sign-on). */
export function LoginPage({ prototypeHint }: { prototypeHint?: boolean }) {
  const { t } = useI18n();
  const { signIn, organizationSignIn, ssoUrl } = useSession();
  const navigate = useNavigate();
  const location = useLocation();
  const { org: slug } = useParams();
  const [search] = useSearchParams();
  const from = safeRedirect((location.state as { from?: unknown } | null)?.from);
  const [errors, setErrors] = useState<{ email?: string; password?: string }>({});
  const [formError, setFormError] = useState<string | null>(search.get('sso_error') ? t('auth.ssoFailed') : null);
  const [busy, setBusy] = useState(false);
  const [reveal, setReveal] = useState(false);
  const [loaded, setLoaded] = useState<{ slug: string; org: OrgSignIn | null } | null>(null);
  const [usePassword, setUsePassword] = useState(false);
  const [findOrg, setFindOrg] = useState(false);
  const [orgSlug, setOrgSlug] = useState('');
  const emailRef = useRef<HTMLInputElement>(null);
  const passwordRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (!slug) return;
    let live = true;
    organizationSignIn(slug).then(
      (o) => { if (live) setLoaded({ slug, org: o }); },
      () => { if (live) setLoaded({ slug, org: null }); },
    );
    return () => { live = false; };
  }, [slug, organizationSignIn]);
  const org: OrgState = !slug ? { status: 'none' } : loaded?.slug !== slug ? { status: 'loading' } : loaded.org ? { status: 'ready', org: loaded.org } : { status: 'missing' };

  const onSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    const email = String(data.get('email') ?? '').trim();
    const password = String(data.get('password') ?? '');
    const next = {
      email: !email ? t('auth.emailRequired') : !emailPattern.test(email) ? t('auth.emailInvalid') : undefined,
      password: !password ? t('auth.passwordRequired') : undefined,
    };
    setErrors(next);
    setFormError(null);
    if (next.email) return emailRef.current?.focus();
    if (next.password) return passwordRef.current?.focus();

    setBusy(true);
    try {
      const result = await signIn(email, password);
      navigate(result === 'mfa' ? '/mfa' : from, { replace: true, state: { from } });
    } catch (error) {
      const code = error instanceof AuthError ? error.code : 'unknown';
      setFormError(code === 'invalid_credentials' ? t('auth.invalidCredentials') : code === 'sso_required' ? t('auth.ssoRequired') : t('auth.genericError'));
      passwordRef.current?.select();
    } finally {
      setBusy(false);
    }
  };

  if (org.status === 'loading') return <AuthLayout title={t('auth.signInTitle')}><LoadingState label={t('auth.orgLoading')} /></AuthLayout>;

  const ready = org.status === 'ready' ? org.org : null;
  const brand: AuthBrand | undefined = ready
    ? { name: ready.branding.name ?? ready.name, logoUrl: ready.branding.logo_url, color: ready.branding.color }
    : undefined;
  const sso = ready?.sso.enabled ?? false;
  const showPassword = !ready?.sso.enforced || usePassword;

  const passwordForm = (
    <form noValidate onSubmit={onSubmit} className="flex flex-col gap-5">
      <Input ref={emailRef} name="email" type="email" label={t('auth.email')} autoComplete="username" required error={errors.email} size="lg" />
      <Input ref={passwordRef} name="password" type={reveal ? 'text' : 'password'} label={t('auth.password')} autoComplete="current-password" required
        error={errors.password} size="lg"
        trailing={<IconButton size="sm" variant="tertiary" label={reveal ? t('auth.hidePassword') : t('auth.showPassword')} aria-pressed={reveal}
          icon={<span aria-hidden="true" className="text-xs font-medium">{reveal ? 'Aa' : '••'}</span>} onClick={() => setReveal((r) => !r)} />} />
      <div className="-mt-2 flex justify-end">
        <Link to="/forgot-password" className="rounded-sm text-sm font-medium text-fg-link hover:underline">{t('auth.forgotLink')}</Link>
      </div>
      <Button type="submit" size="lg" fullWidth loading={busy} variant={sso ? 'secondary' : 'primary'}>{t('auth.signIn')}</Button>
    </form>
  );

  return (
    <AuthLayout brand={brand} title={ready ? t('auth.signInToOrg', { name: brand!.name }) : t('auth.signInTitle')}
      subtitle={sso ? t('auth.signInSsoSubtitle') : t('auth.signInSubtitle')}
      footer={prototypeHint ? <p><strong className="font-medium text-fg">{t('shell.prototype')}:</strong> owner@wingora.test · hr@wingora.test · employee@wingora.test</p> : undefined}>
      {org.status === 'missing' && <FormAlert>{t('auth.orgNotFound')}</FormAlert>}
      {formError && <FormAlert>{formError}</FormAlert>}
      {sso && (
        <>
          <Button size="lg" fullWidth onClick={() => window.location.assign(ssoUrl(ready!.slug))}>{t('auth.signInWithSso')}</Button>
          {showPassword && <p className="my-5 flex items-center gap-3 text-xs text-fg-muted before:h-px before:flex-1 before:bg-line after:h-px after:flex-1 after:bg-line">{t('auth.or')}</p>}
        </>
      )}
      {showPassword ? passwordForm : (
        <button type="button" className="mt-5 rounded-sm text-sm font-medium text-fg-link hover:underline" onClick={() => setUsePassword(true)}>{t('auth.ownerPassword')}</button>
      )}
      {!slug && (
        <div className="mt-6 border-t border-line pt-5">
          {findOrg ? (
            <form className="flex items-end gap-3" onSubmit={(e) => { e.preventDefault(); const s = orgSlug.trim().toLowerCase(); if (slugPattern.test(s)) navigate(`/login/${s}`); }}>
              <Input label={t('auth.orgId')} hint={t('auth.orgIdHint')} value={orgSlug} maxLength={63} autoComplete="organization" onChange={(e) => setOrgSlug(e.target.value)} className="flex-1" />
              <Button type="submit" variant="secondary" disabled={!slugPattern.test(orgSlug.trim().toLowerCase())}>{t('auth.continue')}</Button>
            </form>
          ) : (
            <Button variant="tertiary" fullWidth onClick={() => setFindOrg(true)}>{t('auth.useSso')}</Button>
          )}
        </div>
      )}
      {slug && <p className="mt-6 text-center text-sm"><Link to="/login" className="rounded-sm font-medium text-fg-link hover:underline">{t('auth.otherOrg')}</Link></p>}
    </AuthLayout>
  );
}
