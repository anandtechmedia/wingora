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

  const signOut = useCallback(async () => {
    await gateway.signOut();
    setState({ status: 'signed-out' });
  }, [gateway]);

  const value = useMemo<SessionValue>(() => {
    const me = state.status === 'signed-in' ? state.me : null;
    return {
      state, me, permissions: me ? new Set(me.permissions) : noPermissions,
      signIn, verifyMfa, signOut, requestPasswordReset: (email) => gateway.requestPasswordReset(email),
    };
  }, [state, signIn, verifyMfa, signOut, gateway]);

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): SessionValue {
  const value = useContext(SessionContext);
  if (!value) throw new Error('useSession must be used inside <SessionProvider>');
  return value;
}

/** Sends signed-out users to sign-in, remembering where they were going. */
export function RequireAuth({ children }: { children: ReactNode }) {
  const { state } = useSession();
  const location = useLocation();
  if (state.status !== 'signed-in') return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />;
  return children;
}

/** Keeps signed-in users out of the sign-in pages. */
export function PublicOnly({ children }: { children: ReactNode }) {
  const { state } = useSession();
  if (state.status === 'signed-in') return <Navigate to="/dashboard" replace />;
  return children;
}

/** Only allows open redirects to same-origin application paths. */
export function safeRedirect(target: unknown, fallback = '/dashboard'): string {
  return typeof target === 'string' && target.startsWith('/') && !target.startsWith('//') && !target.startsWith('/\\') ? target : fallback;
}
