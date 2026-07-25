// Mirrors the API's User/Tokens schemas (packages/api-contracts/openapi.yaml).
// In a later phase these are generated from the OpenAPI spec.

export type Role = 'admin' | 'moderator' | 'user';

export interface User {
  id: string;
  name: string;
  email: string;
  phone: string | null;
  email_verified: boolean;
  avatar_url: string | null;
  roles: Role[];
  permissions: string[];
  preferences: Record<string, unknown>;
  two_factor_enabled: boolean;
  status: 'active' | 'suspended';
}

export interface Tokens {
  access_token: string;
  token_type: string;
  expires_in: number;
  refresh_token: string;
}

export interface AuthPayload {
  user: User;
  tokens: Tokens;
}

export interface TwoFactorChallenge {
  two_factor_required: true;
  challenge_token: string;
}

export type LoginResult = AuthPayload | TwoFactorChallenge;

export function isTwoFactorChallenge(result: LoginResult): result is TwoFactorChallenge {
  return 'two_factor_required' in result;
}
