import { ApiError, apiRequest, ensureCsrfCookie } from '@/api/http';
import { AuthError, type AuthErrorCode, type AuthGateway, type MeResponse } from './types';

interface MfaSetupResponse { secret: string; otpauth_url: string; qr_code: string }

/** Real browser session against the Laravel API (Sanctum SPA cookies + CSRF). */
export function createLiveGateway(): AuthGateway {
  const me = () => apiRequest<MeResponse>('GET', '/api/v1/auth/me');
  const fail = (error: unknown, code: AuthErrorCode): never => {
    throw new AuthError(error instanceof ApiError && [401, 422, 423].includes(error.status) ? code : 'unknown');
  };

  return {
    async signIn(email, password) {
      await ensureCsrfCookie();
      const result = await apiRequest<{ mfa_required?: boolean } | null>('POST', '/api/v1/auth/login', { body: { email, password } })
        .catch((e: unknown) => fail(e, 'invalid_credentials'));
      if (result?.mfa_required) return { kind: 'mfa_required' };
      return { kind: 'signed_in', me: await me() };
    },
    async verifyMfa(input) {
      const body = 'code' in input ? { code: input.code } : { recovery_code: input.recoveryCode };
      await apiRequest('POST', '/api/v1/auth/login/mfa', { body }).catch((e: unknown) => fail(e, 'mfa_invalid'));
      return me();
    },
    async requestPasswordReset(email) {
      await ensureCsrfCookie();
      await apiRequest('POST', '/api/v1/auth/password/forgot', { body: { email } });
    },
    me,
    async startMfaSetup() {
      const r = await apiRequest<MfaSetupResponse>('POST', '/api/v1/auth/mfa/setup').catch((e: unknown) => fail(e, 'unknown'));
      return { secret: r.secret, otpauthUrl: r.otpauth_url, qrCode: r.qr_code };
    },
    async confirmMfaSetup(code) {
      const r = await apiRequest<{ recovery_codes: string[] }>('POST', '/api/v1/auth/mfa/confirm', { body: { code } }).catch((e: unknown) => fail(e, 'mfa_invalid'));
      return r.recovery_codes;
    },
    async signOut() {
      await apiRequest('POST', '/api/v1/auth/logout').catch(() => undefined);
    },
  };
}
