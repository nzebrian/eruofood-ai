import { MemoryRouter } from 'react-router-dom';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { User } from '@features/auth/types';
import { ProfilePage } from './ProfilePage';

/**
 * M48 / F-10.
 *
 * `/account` was the one authenticated destination that did not render inside
 * `Layout`. The header links to it as "Account", so a user could arrive and
 * find the navigation gone — the browser back button was the only way out.
 * It is also the only place `logout()` is called from, which made the dead end
 * worse: signing out required first reaching a page with no way onward.
 */

const user: User = {
  id: 'u1',
  name: 'Ada Eze',
  email: 'ada@example.com',
  phone: null,
  roles: ['user'],
  permissions: [],
  preferences: {},
  avatar_url: null,
  email_verified: true,
  two_factor_enabled: false,
  status: 'active',
};

vi.mock('@features/auth/useAuth', () => ({
  useAuth: () => ({
    user,
    loading: false,
    logout: vi.fn(),
    refreshUser: vi.fn(),
  }),
}));

vi.mock('@features/auth/authApi', () => ({
  authApi: { updateProfile: vi.fn() },
}));

describe('ProfilePage', () => {
  it('renders inside the application chrome, so the page is not a dead end', () => {
    render(
      <MemoryRouter>
        <ProfilePage />
      </MemoryRouter>,
    );

    expect(screen.getByRole('heading', { name: /my profile/i })).toBeInTheDocument();
    // The header's brand link is only present when Layout wrapped the page.
    expect(screen.getByRole('link', { name: 'EruoFood AI' })).toHaveAttribute('href', '/');
    expect(screen.getByRole('link', { name: 'Foods' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Search' })).toBeInTheDocument();
  });

  it('still offers the only sign-out control in the application', () => {
    render(
      <MemoryRouter>
        <ProfilePage />
      </MemoryRouter>,
    );

    expect(screen.getByRole('button', { name: /sign out/i })).toBeInTheDocument();
  });

  it('shows the signed-in account details and an editable form', () => {
    render(
      <MemoryRouter>
        <ProfilePage />
      </MemoryRouter>,
    );

    expect(screen.getByText(/ada@example.com/)).toBeInTheDocument();
    expect(screen.getByLabelText(/full name/i)).toHaveValue('Ada Eze');
    expect(screen.getByRole('button', { name: /save changes/i })).toBeInTheDocument();
  });
});
