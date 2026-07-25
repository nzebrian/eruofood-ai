import { config } from '@config/env';

/**
 * Application shell. Intentionally minimal in the foundation phase — routing,
 * providers, and feature composition are added as features arrive.
 */
export function App(): React.JSX.Element {
  return (
    <main>
      <h1>{config.appName}</h1>
      <p>Enterprise foundation is ready. Environment: {config.appEnv}.</p>
    </main>
  );
}

export default App;
