/**
 * Persists auth tokens in localStorage. Kept isolated so the storage mechanism
 * (localStorage today; httpOnly cookies later) can change without touching
 * feature code.
 */
const ACCESS_KEY = 'eruofood.access_token';
const REFRESH_KEY = 'eruofood.refresh_token';

export interface StoredTokens {
  accessToken: string;
  refreshToken: string;
}

export const tokenStorage = {
  get(): StoredTokens | null {
    const accessToken = localStorage.getItem(ACCESS_KEY);
    const refreshToken = localStorage.getItem(REFRESH_KEY);
    return accessToken && refreshToken ? { accessToken, refreshToken } : null;
  },
  set(tokens: StoredTokens): void {
    localStorage.setItem(ACCESS_KEY, tokens.accessToken);
    localStorage.setItem(REFRESH_KEY, tokens.refreshToken);
  },
  clear(): void {
    localStorage.removeItem(ACCESS_KEY);
    localStorage.removeItem(REFRESH_KEY);
  },
};
