/**
 * Typed, validated access to build-time environment variables.
 *
 * Centralising env access here keeps `import.meta.env` out of feature code and
 * gives a single place to validate configuration at startup.
 */
export interface AppConfig {
  apiBaseUrl: string;
  appName: string;
  appEnv: string;
}

function required(value: string | undefined, key: string): string {
  if (!value) {
    throw new Error(`Missing required environment variable: ${key}`);
  }
  return value;
}

export const config: AppConfig = {
  apiBaseUrl: required(import.meta.env.VITE_API_BASE_URL, 'VITE_API_BASE_URL'),
  appName: import.meta.env.VITE_APP_NAME ?? 'EruoFood AI',
  appEnv: import.meta.env.VITE_APP_ENV ?? 'local',
};
