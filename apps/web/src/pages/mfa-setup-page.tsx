import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Navigate, useLocation, useNavigate } from 'react-router';
import { Button, ErrorState, Input, LoadingState } from '@/components/ui';
import { AuthError, type MfaSetup } from '@/auth/types';
import { safeRedirect, useSession } from '@/auth/session';
import { useI18n } from '@/i18n/i18n';
import { AuthLayout, FormAlert } from './auth-layout';

/** First-time MFA enrolment for roles that require it: scan, confirm a code, save the recovery codes. */
export function MfaSetupPage() {
  const { t } = useI18n();
  const { state, startMfaSetup, confirmMfaSetup, refresh, signOut } = useSession();
  const navigate = useNavigate();
  const location = useLocation();
  const from = safeRedirect((location.state as { from?: unknown } | null)?.from);
  const [setup, setSetup] = useState<MfaSetup | null>(null);
  const [loadFailed, setLoadFailed] = useState(false);
  const [codes, setCodes] = useState<string[] | null>(null);
  const [fieldError, setFieldError] = useState<string>();
  const [formError, setFormError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [copied, setCopied] = useState(false);
  const started = useRef(false);
  const ref = useRef<HTMLInputElement>(null);
  const needsSetup = state.status === 'signed-in' && state.me.mfa_enrollment_required;

  const load = () => {
    setLoadFailed(false);
    startMfaSetup().then(setSetup, () => setLoadFailed(true));
  };

  // Recovery codes are shown once; MFA is already on, so warn before a reload or close loses them.
  useEffect(() => {
    if (!codes) return;
    const warn = (event: BeforeUnloadEvent) => {
      event.preventDefault();
      event.returnValue = '';
    };
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [codes]);

  useEffect(() => {
    if (!needsSetup || started.current) return;
    started.current = true;
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps -- start exactly one enrolment per visit
  }, [needsSetup]);

  if (state.status !== 'signed-in') return <Navigate to="/login" replace />;
  if (!needsSetup && !codes) return <Navigate to={from} replace />;

  const onSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const value = String(new FormData(event.currentTarget).get('code') ?? '').replace(/\s/g, '');
    const invalid = /^\d{6}$/.test(value) ? undefined : t('auth.mfaFormat');
    setFieldError(invalid);
    setFormError(null);
    if (invalid) return ref.current?.focus();
    setBusy(true);
    try {
      setCodes(await confirmMfaSetup(value));
    } catch (error) {
      setFormError(error instanceof AuthError && error.code === 'mfa_invalid' ? t('auth.mfaInvalid') : t('auth.genericError'));
      ref.current?.select();
    } finally {
      setBusy(false);
    }
  };

  const finish = async () => {
    setBusy(true);
    try {
      await refresh();
      navigate(from, { replace: true });
    } catch {
      setFormError(t('auth.genericError'));
      setBusy(false);
    }
  };

  const copy = async () => {
    if (!codes) return;
    try {
      await navigator.clipboard.writeText(codes.join('\n'));
      setCopied(true);
    } catch {
      setCopied(false);
    }
  };

  const leave = async () => {
    await signOut();
    navigate('/login', { replace: true });
  };

  const footer = <Button variant="link" onClick={() => void leave()}>{t('auth.mfaSetupSignOut')}</Button>;

  if (codes) {
    return (
      <AuthLayout title={t('auth.mfaCodesTitle')} subtitle={t('auth.mfaCodesSubtitle')}>
        {formError && <FormAlert>{formError}</FormAlert>}
        <ul aria-label={t('auth.mfaCodesTitle')} className="grid grid-cols-2 gap-2 rounded-md border border-line bg-surface-muted p-4 font-mono text-sm text-fg">
          {codes.map((c) => <li key={c}>{c}</li>)}
        </ul>
        <div className="mt-5 flex flex-col gap-3">
          <Button variant="secondary" fullWidth onClick={() => void copy()}>{copied ? t('auth.mfaCodesCopied') : t('auth.mfaCodesCopy')}</Button>
          <Button size="lg" fullWidth loading={busy} onClick={() => void finish()}>{t('auth.mfaCodesContinue')}</Button>
        </div>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout title={t('auth.mfaSetupTitle')} subtitle={t('auth.mfaSetupSubtitle')} footer={footer}>
      {loadFailed ? <ErrorState title={t('auth.mfaSetupFailed')} onRetry={load} />
        : !setup ? <LoadingState label={t('auth.mfaSetupLoading')} />
        : (
          <div className="flex flex-col gap-6">
            <ol className="flex flex-col gap-4 text-sm text-fg-secondary">
              <li>
                <p>{t('auth.mfaSetupStepScan')}</p>
                <img src={setup.qrCode} alt={t('auth.mfaSetupQrAlt')} width={192} height={192} className="mx-auto mt-3 rounded-md border border-line bg-white p-2" />
              </li>
              <li>
                <p>{t('auth.mfaSetupStepKey')}</p>
                <code className="mt-2 block break-all rounded-md border border-line bg-surface-muted p-3 font-mono text-sm text-fg">{setup.secret.replace(/(.{4})/g, '$1 ').trim()}</code>
              </li>
            </ol>
            {formError && <FormAlert>{formError}</FormAlert>}
            <form noValidate onSubmit={onSubmit} className="flex flex-col gap-5">
              <Input ref={ref} name="code" label={t('auth.mfaCode')} hint={t('auth.mfaSetupStepCode')} inputMode="numeric" autoComplete="one-time-code" maxLength={6} pattern="\d{6}"
                required error={fieldError} size="lg" className="font-mono tracking-[0.3em]" />
              <Button type="submit" size="lg" fullWidth loading={busy}>{t('auth.mfaSetupConfirm')}</Button>
            </form>
          </div>
        )}
    </AuthLayout>
  );
}
