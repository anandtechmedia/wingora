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

/** `POST /api/v1/auth/mfa/setup`: a fresh, unconfirmed secret for the signed-in user. */
export interface MfaSetup {
  secret: string;
  otpauthUrl: string;
  /** Server-rendered QR code as a `data:image/svg+xml` URI. */
  qrCode: string;
}

export type SignInResult = { kind: 'mfa_required' } | { kind: 'signed_in'; me: MeResponse };

/** Authentication transport. The mock implementation is replaced by `@wingora/api-client` once it exists. */
export interface AuthGateway {
  signIn(email: string, password: string): Promise<SignInResult>;
  verifyMfa(input: { code: string } | { recoveryCode: string }): Promise<MeResponse>;
  requestPasswordReset(email: string): Promise<void>;
  /** Re-reads the session, e.g. after MFA enrolment changes what it allows. */
  me(): Promise<MeResponse>;
  startMfaSetup(): Promise<MfaSetup>;
  /** Confirms the pending secret with a code and returns the one-time recovery codes. */
  confirmMfaSetup(code: string): Promise<string[]>;
  signOut(): Promise<void>;
}
