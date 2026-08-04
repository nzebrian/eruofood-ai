import '@testing-library/jest-dom';
import { vi } from 'vitest';

// Provide the env variables the app expects during tests.
vi.stubEnv('VITE_API_BASE_URL', 'http://localhost/api/v1');
vi.stubEnv('VITE_APP_NAME', 'EruoFood AI');
vi.stubEnv('VITE_APP_ENV', 'test');
