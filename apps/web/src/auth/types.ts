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
  organization: { id: string; name: string; country_code: string; slug?: string; branding?: OrgBranding } | null;
  /** True when this session came from the organization's identity provider (Wingora's own two-step check is skipped). */
  signed_in_with_sso?: boolean;
  permissions: string[];
  /** Subscription state; absent for platform staff and in the prototype. */
  billing?: BillingSummary | null;
}

/** White-label branding an organization sets for its sign-in page and app header. */
export interface OrgBranding { name: string | null; color: string | null; logo_url: string | null }

/** `GET /api/v1/public/organizations/{slug}`: what an organization's sign-in page shows before anyone signs in. */
export interface OrgSignIn { slug: string; name: string; branding: OrgBranding; sso: { enabled: boolean; enforced: boolean } }

export type AuthErrorCode = 'invalid_credentials' | 'sso_required' | 'mfa_invalid' | 'mfa_expired' | 'not_found' | 'unknown';

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
  organizationSignIn(slug: string): Promise<OrgSignIn>;
  /** Full-page URL that starts single sign-on with the organization's identity provider. */
  ssoUrl(slug: string): string;
}
