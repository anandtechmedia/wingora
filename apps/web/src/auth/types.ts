import type { BillingSummary } from '@/api/billing';

/** Shape of `GET /api/v1/auth/me` (backend `MeController` + `UserResource`). Keep in sync with the backend. */
export interface MeResponse {
  user: {
    id: string;
    name: string;
    email: string;
    status: string;
    roles: string[];
    mfa_enabled: boolean;
    last_login_at: string | null;
  };
  mfa_enrollment_required: boolean;
  organization: { id: string; name: string; country_code: string } | null;
  permissions: string[];
  /** Subscription state; absent for platform staff and in the prototype. */
  billing?: BillingSummary | null;
}

export type AuthErrorCode = 'invalid_credentials' | 'mfa_invalid' | 'mfa_expired' | 'unknown';

export class AuthError extends Error {
  constructor(public readonly code: AuthErrorCode) {
    super(code);
    this.name = 'AuthError';
  }
}

export type SignInResult = { kind: 'mfa_required' } | { kind: 'signed_in'; me: MeResponse };

/** Authentication transport. The mock implementation is replaced by `@wingora/api-client` once it exists. */
export interface AuthGateway {
  signIn(email: string, password: string): Promise<SignInResult>;
  verifyMfa(input: { code: string } | { recoveryCode: string }): Promise<MeResponse>;
  requestPasswordReset(email: string): Promise<void>;
  signOut(): Promise<void>;
}
