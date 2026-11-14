import { AuthError, type AuthGateway, type MeResponse } from '@/auth/types';
import { personas } from './fixtures';

/** PROTOTYPE: in-memory auth for visual prototyping. Any password works except "wrong"; MFA rejects 000000. */
export function createMockGateway(delayMs = 400): AuthGateway {
  let pending: MeResponse | null = null;
  let current: MeResponse | null = null;
  let setupStarted = false;
  const wait = () => new Promise((r) => setTimeout(r, delayMs));

  return {
    async signIn(email, password) {
      await wait();
      const me = personas[email.trim().toLowerCase()];
      if (!me || password === 'wrong') throw new AuthError('invalid_credentials');
      if (me.user.mfa_enabled) {
        pending = me;
        return { kind: 'mfa_required' };
      }
      current = me;
      return { kind: 'signed_in', me };
    },
    async verifyMfa(input) {
      await wait();
      if (!pending) throw new AuthError('mfa_expired');
      const valid = 'code' in input ? /^\d{6}$/.test(input.code) && input.code !== '000000' : input.recoveryCode.trim().length >= 8;
      if (!valid) throw new AuthError('mfa_invalid');
      const me = pending;
      pending = null;
      current = me;
      return me;
    },
    async requestPasswordReset() {
      await wait();
    },
    async me() {
      await wait();
      if (!current) throw new AuthError('mfa_expired');
      return current;
    },
    async startMfaSetup() {
      await wait();
      setupStarted = true;
      const secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
      const svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><rect width="8" height="8" fill="#fff"/><path d="M1 1h2v2H1zM5 1h2v2H5zM1 5h2v2H1zM4 4h1v1H4z"/></svg>';
      return { secret, otpauthUrl: `otpauth://totp/Wingora:${current?.user.email ?? ''}?secret=${secret}&issuer=Wingora`, qrCode: `data:image/svg+xml;base64,${btoa(svg)}` };
    },
    async confirmMfaSetup(code) {
      await wait();
      if (!current || !setupStarted) throw new AuthError('mfa_expired');
      if (!/^\d{6}$/.test(code) || code === '000000') throw new AuthError('mfa_invalid');
      current = { ...current, user: { ...current.user, mfa_enabled: true }, mfa_enrollment_required: false };
      return Array.from({ length: 10 }, (_, i) => `RCVR-${String(i).padStart(4, '0')}-DEMO`);
    },
    async signOut() {
      pending = null;
      current = null;
    },
  };
}
