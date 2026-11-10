import { useRef, useState, type FormEvent } from 'react';
import { Link } from 'react-router';
import { ArrowLeftIcon, Button, CheckCircleIcon, Input } from '@/components/ui';
import { useSession } from '@/auth/session';
import { useI18n } from '@/i18n/i18n';
import { AuthLayout } from './auth-layout';

const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/** The response never reveals whether the account exists (mirrors the backend's uniform 202). */
export function ForgotPasswordPage() {
  const { t } = useI18n();
  const { requestPasswordReset } = useSession();
  const [error, setError] = useState<string>();
  const [sentTo, setSentTo] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const ref = useRef<HTMLInputElement>(null);

  const onSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const email = String(new FormData(event.currentTarget).get('email') ?? '').trim();
    const message = !email ? t('auth.emailRequired') : !emailPattern.test(email) ? t('auth.emailInvalid') : undefined;
    setError(message);
    if (message) return ref.current?.focus();
    setBusy(true);
    try {
      await requestPasswordReset(email);
    } finally {
      setBusy(false);
      setSentTo(email);
    }
  };

  const back = (
    <Link to="/login" className="inline-flex items-center gap-1.5 rounded-sm font-medium text-fg-link hover:underline">
      <ArrowLeftIcon size={14} className="rtl:rotate-180" />{t('auth.backToSignIn')}
    </Link>
  );

  if (sentTo) {
    return (
      <AuthLayout title={t('auth.resetSentTitle')} footer={back}>
        <div role="status" className="flex gap-3 rounded-md border border-success-line bg-success-subtle p-4 text-sm text-success-fg">
          <CheckCircleIcon size={18} className="mt-0.5 shrink-0" />
          <p>{t('auth.resetSent', { email: sentTo })}</p>
        </div>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout title={t('auth.forgotTitle')} subtitle={t('auth.forgotSubtitle')} footer={back}>
      <form noValidate onSubmit={onSubmit} className="flex flex-col gap-5">
        <Input ref={ref} name="email" type="email" label={t('auth.email')} autoComplete="username" required error={error} size="lg" />
        <Button type="submit" size="lg" fullWidth loading={busy}>{t('auth.sendLink')}</Button>
      </form>
    </AuthLayout>
  );
}
