import type { ReactNode } from 'react';
import { Navigate } from 'react-router-dom';
import { useAuth } from '@features/auth/useAuth';

/** Gate that redirects unauthenticated users to the login page. */
export function ProtectedRoute({ children }: { children: ReactNode }): React.JSX.Element {
  const { user, loading } = useAuth();

  if (loading) {
    return <p className="page">Loading…</p>;
  }

  return user ? <>{children}</> : <Navigate to="/login" replace />;
}
