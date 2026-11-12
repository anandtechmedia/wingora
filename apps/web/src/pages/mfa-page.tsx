import { useRef, useState, type FormEvent } from 'react';
import { Link, Navigate, useLocation, useNavigate } from 'react-router';
import { Button, Input } from '@/components/ui';
import { AuthError } from '@/auth/types';
import { safeRedirect, useSession } from '@/auth/session';
import { useI18n } from '@/i18n/i18n';
import { AuthLayout, FormAlert } from './auth-layout';

export function MfaPage() {
  const { t } = useI18n();
  const { state, verifyMfa } = useSession();
  const navigate = useNavigate();
  const location = useLocation();
  const from = safeRedirect((location.state as { from?: unknown } | null)?.from);
  const [recovery, setRecovery] = useState(false);
  const [fieldError, setFieldError] = useState<string>();
  const [formError, setFormError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const ref = useRef<HTMLInputElement>(null);

  if (state.status === 'signed-in') return <Navigate to={from} replace />;
  if (state.status !== 'mfa') return <Navigate to="/login" replace />;

  const onSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const value = String(new FormData(event.currentTarget).get('code') ?? '').replace(/\s/g, '');
    const invalid = recovery ? (!value ? t('auth.recoveryRequired') : undefined) : (!/^\d{6}$/.test(value) ? t('auth.mfaFormat') : undefined);
    setFieldError(invalid);
    setFormError(null);
    if (invalid) return ref.current?.focus();
    setBusy(true);
    try {
      await verifyMfa(recovery ? { recoveryCode: value } : { code: value });
      navigate(from, { replace: true });
    } catch (error) {
      const code = error instanceof AuthError ? error.code : 'unknown';
      if (code === 'mfa_expired') return navigate('/login', { replace: true });
      setFormError(code === 'mfa_invalid' ? t('auth.mfaInvalid') : t('auth.genericError'));
      ref.current?.select();
    } finally {
      setBusy(false);
    }
  };

  const toggle = () => {
    setRecovery((r) => !r);
    setFieldError(undefined);
    setFormError(null);
    requestAnimationFrame(() => ref.current?.focus());
  };

  return (
    <AuthLayout title={t('auth.mfaTitle')} subtitle={recovery ? t('auth.mfaRecoverySubtitle') : t('auth.mfaSubtitle')}
      footer={<Link to="/login" replace className="rounded-sm font-medium text-fg-link hover:underline">{t('auth.backToSignIn')}</Link>}>
      {formError && <FormAlert>{formError}</FormAlert>}
      <form noValidate onSubmit={onSubmit} className="flex flex-col gap-5">
        {recovery
          ? <Input key="recovery" ref={ref} name="code" label={t('auth.recoveryCode')} autoComplete="off" autoCapitalize="characters" spellCheck={false} required error={fieldError} size="lg" />
          : <Input key="totp" ref={ref} name="code" label={t('auth.mfaCode')} inputMode="numeric" autoComplete="one-time-code" maxLength={6} pattern="\d{6}"
              required error={fieldError} size="lg" className="font-mono tracking-[0.3em]" />}
        <Button type="submit" size="lg" fullWidth loading={busy}>{t('auth.verify')}</Button>
        <Button variant="link" onClick={toggle} className="self-center">{recovery ? t('auth.useCode') : t('auth.useRecovery')}</Button>
      </form>
    </AuthLayout>
  );
}
