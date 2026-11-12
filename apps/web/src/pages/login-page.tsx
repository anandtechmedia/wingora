import { useRef, useState, type FormEvent } from 'react';
import { Link, useLocation, useNavigate } from 'react-router';
import { Button, IconButton, Input } from '@/components/ui';
import { AuthError } from '@/auth/types';
import { safeRedirect, useSession } from '@/auth/session';
import { useI18n } from '@/i18n/i18n';
import { AuthLayout, FormAlert } from './auth-layout';

const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export function LoginPage({ prototypeHint }: { prototypeHint?: boolean }) {
  const { t } = useI18n();
  const { signIn } = useSession();
  const navigate = useNavigate();
  const location = useLocation();
  const from = safeRedirect((location.state as { from?: unknown } | null)?.from);
  const [errors, setErrors] = useState<{ email?: string; password?: string }>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [reveal, setReveal] = useState(false);
  const emailRef = useRef<HTMLInputElement>(null);
  const passwordRef = useRef<HTMLInputElement>(null);

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
      setFormError(error instanceof AuthError && error.code === 'invalid_credentials' ? t('auth.invalidCredentials') : t('auth.genericError'));
      passwordRef.current?.select();
    } finally {
      setBusy(false);
    }
  };

  return (
    <AuthLayout title={t('auth.signInTitle')} subtitle={t('auth.signInSubtitle')}
      footer={prototypeHint ? <p><strong className="font-medium text-fg">{t('shell.prototype')}:</strong> owner@wingora.test · hr@wingora.test · employee@wingora.test</p> : undefined}>
      {formError && <FormAlert>{formError}</FormAlert>}
      <form noValidate onSubmit={onSubmit} className="flex flex-col gap-5">
        <Input ref={emailRef} name="email" type="email" label={t('auth.email')} autoComplete="username" required error={errors.email} size="lg" />
        <Input ref={passwordRef} name="password" type={reveal ? 'text' : 'password'} label={t('auth.password')} autoComplete="current-password" required
          error={errors.password} size="lg"
          trailing={<IconButton size="sm" variant="tertiary" label={reveal ? t('auth.hidePassword') : t('auth.showPassword')} aria-pressed={reveal}
            icon={<span aria-hidden="true" className="text-xs font-medium">{reveal ? 'Aa' : '••'}</span>} onClick={() => setReveal((r) => !r)} />} />
        <div className="-mt-2 flex justify-end">
          <Link to="/forgot-password" className="rounded-sm text-sm font-medium text-fg-link hover:underline">{t('auth.forgotLink')}</Link>
        </div>
        <Button type="submit" size="lg" fullWidth loading={busy}>{t('auth.signIn')}</Button>
      </form>
    </AuthLayout>
  );
}
