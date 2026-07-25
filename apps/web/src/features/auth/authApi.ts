import { apiClient } from '@lib/apiClient';
import type { AuthPayload, LoginResult, User } from './types';

/** Typed wrappers over the Identity REST endpoints. */
export const authApi = {
  register: (input: {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
  }) => apiClient.post<AuthPayload>('/auth/register', input),

  login: (input: { email: string; password: string }) =>
    apiClient.post<LoginResult>('/auth/login', input),

  loginTwoFactor: (input: { challenge_token: string; code: string }) =>
    apiClient.post<AuthPayload>('/auth/login/two-factor', input),

  logout: (refreshToken: string) =>
    apiClient.post<void>('/auth/logout', { refresh_token: refreshToken }),

  forgotPassword: (email: string) =>
    apiClient.post<{ message: string }>('/auth/password/forgot', { email }),

  resetPassword: (input: {
    email: string;
    token: string;
    password: string;
    password_confirmation: string;
  }) => apiClient.post<{ message: string }>('/auth/password/reset', input),

  verifyEmail: (input: { uid: string; token: string }) =>
    apiClient.post<{ message: string }>('/auth/email/verify', input),

  me: () => apiClient.get<User>('/me'),

  updateProfile: (input: { name: string; phone: string | null }) =>
    apiClient.put<User>('/me', input),

  changePassword: (input: {
    current_password: string;
    password: string;
    password_confirmation: string;
  }) => apiClient.put<{ message: string }>('/me/password', input),
};
