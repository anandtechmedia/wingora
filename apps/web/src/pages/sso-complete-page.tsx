import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router';
import { LoadingState } from '@/components/ui';
import { useSession } from '@/auth/session';
import { useI18n } from '@/i18n/i18n';
import { AuthLayout, FormAlert } from './auth-layout';

/** Landing page after the identity provider: the server has set the session cookie, so rebuild the in-memory session. */
export function SsoCompletePage() {
  const { t } = useI18n();
  const { refresh } = useSession();
  const navigate = useNavigate();
  const [failed, setFailed] = useState(false);
  const started = useRef(false);

  useEffect(() => {
    if (started.current) return;
    started.current = true;
    refresh().then(() => navigate('/', { replace: true }), () => setFailed(true));
  }, [refresh, navigate]);

  return (
    <AuthLayout title={t('auth.signInTitle')}>
      {failed ? (
        <>
          <FormAlert>{t('auth.ssoFailed')}</FormAlert>
          <Link to="/login" className="rounded-sm text-sm font-medium text-fg-link hover:underline">{t('auth.backToSignIn')}</Link>
        </>
      ) : <LoadingState label={t('auth.ssoFinishing')} />}
    </AuthLayout>
  );
}
