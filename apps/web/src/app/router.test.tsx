import { RouterProvider, createMemoryRouter } from 'react-router-dom';
import type { RouteObject } from 'react-router-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AuthProvider } from '@features/auth/AuthProvider';
import { routes } from './router';
import { RouteErrorBoundary } from './RouteErrorBoundary';

/**
 * M48 / F-10.
 *
 * The router shipped with 50 routes, no `errorElement` and no catch-all, so
 * both an unknown address and a render-time throw reached React Router's
 * built-in "Unexpected Application Error!" screen — unstyled, unbranded, with
 * a stack trace and no way back into the application.
 */

/** Every page fetches on mount; keep that off the network and deterministic. */
function stubFetch(): void {
  vi.stubGlobal(
    'fetch',
    vi.fn(() => Promise.reject(new TypeError('Failed to fetch'))),
  );
}

beforeEach(() => {
  stubFetch();
  localStorage.clear();
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

function renderAt(initialEntry: string): void {
  const router = createMemoryRouter(routes, { initialEntries: [initialEntry] });
  render(
    <AuthProvider>
      <RouterProvider router={router} />
    </AuthProvider>,
  );
}

describe('route table', () => {
  it('wraps every screen in one error boundary', () => {
    expect(routes).toHaveLength(1);
    expect(routes[0]?.errorElement).toBeDefined();
  });

  it('ends with a catch-all, which must stay last to avoid shadowing real routes', () => {
    const children = routes[0]?.children ?? [];
    expect(children.length).toBeGreaterThan(50);
    expect(children.at(-1)?.path).toBe('*');
    // Nothing else may claim '*', or the last one would be unreachable.
    expect(children.filter((route) => route.path === '*')).toHaveLength(1);
  });
});

describe('unknown addresses', () => {
  it('renders the branded 404 rather than the router default', async () => {
    renderAt('/definitely-not-a-page');

    expect(
      await screen.findByRole('heading', { name: /could not find that page/i }),
    ).toBeInTheDocument();
    expect(screen.getByText('404')).toBeInTheDocument();
    expect(screen.queryByText(/Unexpected Application Error/i)).not.toBeInTheDocument();
  });

  it('offers navigation back into the application', async () => {
    renderAt('/definitely-not-a-page');
    await screen.findByRole('heading', { name: /could not find that page/i });

    expect(screen.getByRole('link', { name: /back to the catalogue/i })).toHaveAttribute(
      'href',
      '/',
    );
    expect(screen.getByRole('link', { name: /search instead/i })).toHaveAttribute(
      'href',
      '/search',
    );
  });

  it('navigates back to a valid destination when the recovery link is used', async () => {
    renderAt('/definitely-not-a-page');
    await screen.findByRole('heading', { name: /could not find that page/i });

    await userEvent.click(screen.getByRole('link', { name: /back to the catalogue/i }));

    // The catalogue took over: the not-found copy is gone and its heading with it.
    expect(
      screen.queryByRole('heading', { name: /could not find that page/i }),
    ).not.toBeInTheDocument();
  });

  it('still resolves a real route, so the catch-all is not shadowing anything', async () => {
    renderAt('/login');

    expect(await screen.findByRole('heading', { name: /sign in/i })).toBeInTheDocument();
  });
});

describe('RouteErrorBoundary', () => {
  function renderThrowing(): void {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    const throwingRoutes: RouteObject[] = [
      {
        errorElement: <RouteErrorBoundary />,
        children: [
          {
            path: '/boom',
            element: <ThrowsOnRender />,
          },
        ],
      },
    ];
    render(
      <RouterProvider router={createMemoryRouter(throwingRoutes, { initialEntries: ['/boom'] })} />,
    );
    expect(consoleError).toHaveBeenCalled();
  }

  it('renders a recovery screen and does not swallow the error', () => {
    renderThrowing();

    const alert = screen.getByRole('alert');
    expect(alert).toHaveTextContent(/this page could not be displayed/i);
    expect(screen.getByRole('button', { name: /reload this page/i })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /back to the catalogue/i })).toHaveAttribute(
      'href',
      '/',
    );
  });

  it('does not show React Router’s development error screen', () => {
    renderThrowing();

    expect(screen.queryByText(/Unexpected Application Error/i)).not.toBeInTheDocument();
  });
});

function ThrowsOnRender(): React.JSX.Element {
  throw new Error('kaboom');
}
