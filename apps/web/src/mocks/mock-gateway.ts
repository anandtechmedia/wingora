import { AuthError, type AuthGateway, type MeResponse } from '@/auth/types';
import { personas } from './fixtures';

/** PROTOTYPE: in-memory auth for visual prototyping. Any password works except "wrong"; MFA rejects 000000. */
export function createMockGateway(delayMs = 400): AuthGateway {
  let pending: MeResponse | null = null;
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
      return { kind: 'signed_in', me };
    },
    async verifyMfa(input) {
      await wait();
      if (!pending) throw new AuthError('mfa_expired');
      const valid = 'code' in input ? /^\d{6}$/.test(input.code) && input.code !== '000000' : input.recoveryCode.trim().length >= 8;
      if (!valid) throw new AuthError('mfa_invalid');
      const me = pending;
      pending = null;
      return me;
    },
    async requestPasswordReset() {
      await wait();
    },
    async signOut() {
      pending = null;
    },
  };
}
