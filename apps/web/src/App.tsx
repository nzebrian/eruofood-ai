import { RouterProvider } from 'react-router-dom';
import { AuthProvider } from '@features/auth/AuthProvider';
import { router } from '@app/router';

/**
 * Application shell: wires the auth context and the router. Feature routing
 * lives in @app/router; auth state in @features/auth.
 */
export function App(): React.JSX.Element {
  return (
    <AuthProvider>
      <RouterProvider router={router} />
    </AuthProvider>
  );
}

export default App;
