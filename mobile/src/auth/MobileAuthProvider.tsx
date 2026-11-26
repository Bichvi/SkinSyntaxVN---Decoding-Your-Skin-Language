import { AppState, Platform } from 'react-native';
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';

import {
  MobileApiError,
  mobileApiRequest,
  forgetAccessToken,
  persistAccessToken,
  restoreAccessToken,
  subscribeToUnauthorized,
  type MobileUser,
} from '@/src/services/mobileApi';

type AuthContextValue = {
  user: MobileUser | null;
  loading: boolean;
  error: string;
  signIn: (email: string, password: string) => Promise<void>;
  signOut: () => Promise<void>;
  refresh: () => Promise<MobileUser | null>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

export function MobileAuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<MobileUser | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const refresh = useCallback(async () => {
    try {
      const response = await mobileApiRequest<{ user: MobileUser }>('auth.me');
      setUser(response.user);
      setError('');
      return response.user;
    } catch (cause) {
      if (cause instanceof MobileApiError && cause.status === 401) {
        await forgetAccessToken();
        setUser(null);
      }
      throw cause;
    }
  }, []);

  useEffect(() => subscribeToUnauthorized(() => {
    void forgetAccessToken();
    setUser(null);
  }), []);

  useEffect(() => {
    let mounted = true;
    const restore = async () => {
      try {
        if (Platform.OS !== 'web') {
          const token = await restoreAccessToken();
          if (!token) return;
        }
        await refresh();
      } catch (cause) {
        if (cause instanceof MobileApiError && cause.status !== 401) {
          if (mounted) setError(cause.message);
        }
      } finally {
        if (mounted) setLoading(false);
      }
    };
    void restore();
    return () => { mounted = false; };
  }, [refresh]);

  useEffect(() => {
    const subscription = AppState.addEventListener('change', state => {
      if (state === 'active' && user) void refresh().catch(() => undefined);
    });
    return () => subscription.remove();
  }, [refresh, user]);

  const signIn = useCallback(async (email: string, password: string) => {
    setError('');
    const response = await mobileApiRequest<{ token?: string; user: MobileUser }>('auth.login', {
      method: 'POST',
      body: { email: email.trim(), password },
      token: null,
    });
    if (response.token) {
      try {
        await persistAccessToken(response.token);
      } catch {
        try {
          await mobileApiRequest('auth.logout', { method: 'POST', token: response.token });
        } catch {
          // The token is never copied to ordinary storage; it expires on the server if revocation is offline.
        }
        await forgetAccessToken();
        throw new Error('Không thể lưu phiên đăng nhập an toàn trên thiết bị này.');
      }
    }
    setUser(response.user);
  }, []);

  const signOut = useCallback(async () => {
    let failure: unknown;
    try {
      await mobileApiRequest('auth.logout', { method: 'POST' });
    } catch (cause) {
      failure = cause;
    } finally {
      await forgetAccessToken();
      setUser(null);
      setError('');
    }
    if (failure) throw failure;
  }, []);

  const value = useMemo(() => ({ user, loading, error, signIn, signOut, refresh }), [user, loading, error, signIn, signOut, refresh]);
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useMobileAuth(): AuthContextValue {
  const context = useContext(AuthContext);
  if (!context) throw new Error('useMobileAuth must be used inside MobileAuthProvider.');
  return context;
}
