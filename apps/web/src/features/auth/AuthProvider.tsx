import { useCallback, useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { tokenStorage } from '@lib/tokenStorage';
import { authApi } from './authApi';
import { AuthContext } from './authContext';
import type { AuthContextValue } from './authContext';
import { isTwoFactorChallenge } from './types';
import type { AuthPayload, LoginResult, User } from './types';

export function AuthProvider({ children }: { children: ReactNode }): React.JSX.Element {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);

  const refreshUser = useCallback(async () => {
    if (!tokenStorage.get()) {
      setUser(null);
      return;
    }
    try {
      setUser(await authApi.me());
    } catch {
      tokenStorage.clear();
      setUser(null);
    }
  }, []);

  useEffect(() => {
    void refreshUser().finally(() => setLoading(false));
  }, [refreshUser]);

  const completeLogin = useCallback((payload: AuthPayload) => {
    tokenStorage.set({
      accessToken: payload.tokens.access_token,
      refreshToken: payload.tokens.refresh_token,
    });
    setUser(payload.user);
  }, []);

  const login = useCallback(
    async (email: string, password: string): Promise<LoginResult> => {
      const result = await authApi.login({ email, password });
      if (!isTwoFactorChallenge(result)) {
        completeLogin(result);
      }
      return result;
    },
    [completeLogin],
  );

  const register = useCallback(
    async (input: {
      name: string;
      email: string;
      password: string;
      password_confirmation: string;
    }) => {
      completeLogin(await authApi.register(input));
    },
    [completeLogin],
  );

  const logout = useCallback(async () => {
    const tokens = tokenStorage.get();
    if (tokens) {
      await authApi.logout(tokens.refreshToken).catch(() => undefined);
    }
    tokenStorage.clear();
    setUser(null);
  }, []);

  const value = useMemo<AuthContextValue>(
    () => ({ user, loading, login, completeLogin, register, logout, refreshUser }),
    [user, loading, login, completeLogin, register, logout, refreshUser],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
