import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router';
import type { AuthGateway, MeResponse } from './types';

type SessionState = { status: 'signed-out' } | { status: 'mfa' } | { status: 'signed-in'; me: MeResponse };

interface SessionValue {
  state: SessionState;
  me: MeResponse | null;
  permissions: ReadonlySet<string>;
  signIn: (email: string, password: string) => Promise<'mfa' | 'signed-in'>;
  verifyMfa: AuthGateway['verifyMfa'];
  requestPasswordReset: AuthGateway['requestPasswordReset'];
  startMfaSetup: AuthGateway['startMfaSetup'];
  confirmMfaSetup: AuthGateway['confirmMfaSetup'];
  /** Reloads the session after enrolment so the app sees the new MFA state. */
  refresh: () => Promise<void>;
  signOut: () => Promise<void>;
}

const SessionContext = createContext<SessionValue | null>(null);
const noPermissions: ReadonlySet<string> = new Set();

/** Session state lives in memory only; nothing about the user is persisted in browser storage. */
export function SessionProvider({ gateway, initial, children }: { gateway: AuthGateway; initial?: MeResponse; children: ReactNode }) {
  const [state, setState] = useState<SessionState>(initial ? { status: 'signed-in', me: initial } : { status: 'signed-out' });

  const signIn = useCallback(async (email: string, password: string) => {
    const result = await gateway.signIn(email, password);
    if (result.kind === 'mfa_required') {
      setState({ status: 'mfa' });
      return 'mfa' as const;
    }
    setState({ status: 'signed-in', me: result.me });
    return 'signed-in' as const;
  }, [gateway]);

  const verifyMfa = useCallback<AuthGateway['verifyMfa']>(async (input) => {
    const me = await gateway.verifyMfa(input);
    setState({ status: 'signed-in', me });
    return me;
  }, [gateway]);

  const refresh = useCallback(async () => {
    setState({ status: 'signed-in', me: await gateway.me() });
  }, [gateway]);

  const signOut = useCallback(async () => {
    await gateway.signOut();
    setState({ status: 'signed-out' });
  }, [gateway]);

  const value = useMemo<SessionValue>(() => {
    const me = state.status === 'signed-in' ? state.me : null;
    return {
      state, me, permissions: me ? new Set(me.permissions) : noPermissions,
      signIn, verifyMfa, signOut, refresh, requestPasswordReset: (email) => gateway.requestPasswordReset(email),
      startMfaSetup: () => gateway.startMfaSetup(), confirmMfaSetup: (code) => gateway.confirmMfaSetup(code),
    };
  }, [state, signIn, verifyMfa, signOut, refresh, gateway]);

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): SessionValue {
  const value = useContext(SessionContext);
  if (!value) throw new Error('useSession must be used inside <SessionProvider>');
  return value;
}

/** Sends signed-out users to sign-in, and users whose role requires MFA to enrolment, remembering where they were going. */
export function RequireAuth({ children }: { children: ReactNode }) {
  const { state } = useSession();
  const location = useLocation();
  const from = location.pathname + location.search;
  if (state.status !== 'signed-in') return <Navigate to="/login" replace state={{ from }} />;
  if (state.me.mfa_enrollment_required) return <Navigate to="/mfa/setup" replace state={{ from }} />;
  return children;
}

/** Keeps signed-in users out of the sign-in pages. */
export function PublicOnly({ children }: { children: ReactNode }) {
  const { state } = useSession();
  const location = useLocation();
  if (state.status === 'signed-in') return <Navigate to={safeRedirect((location.state as { from?: unknown } | null)?.from)} replace />;
  return children;
}

/** Only allows open redirects to same-origin application paths. */
export function safeRedirect(target: unknown, fallback = '/'): string {
  return typeof target === 'string' && target.startsWith('/') && !target.startsWith('//') && !target.startsWith('/\\') ? target : fallback;
}
