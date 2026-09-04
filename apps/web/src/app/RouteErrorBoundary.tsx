import { Link, isRouteErrorResponse, useRouteError } from 'react-router-dom';
import { config } from '@config/env';
import { NotFoundContent } from './NotFound';

/**
 * The application's `errorElement` (M48, F-10).
 *
 * ## What this replaces
 *
 * The router carried 50 route objects and not one `errorElement`. React Router
 * falls back to its own built-in screen in that case — "Unexpected Application
 * Error!" over a stack trace, unstyled, with no navigation. That is a
 * developer diagnostic shown to whoever happened to hit the bug.
 *
 * ## What it does not do
 *
 * It does not swallow the error. Every caught error is written to the console
 * with its stack intact, so browser error reporting and local debugging are
 * unaffected, and outside production the detail is rendered on the page too —
 * a developer loses nothing, while a customer is not shown a stack trace.
 */
export function RouteErrorBoundary(): React.JSX.Element {
  const error = useRouteError();

  // Not swallowed: the full value, stack and all, still reaches the console.
  console.error('Unhandled route error', error);

  // A loader or action that threw a 404 Response is a missing resource, not a
  // crash, and deserves the same answer as an unknown address.
  if (isRouteErrorResponse(error) && error.status === 404) {
    return <NotFoundContent />;
  }

  const status = isRouteErrorResponse(error) ? String(error.status) : '';
  const detail = describeForDeveloper(error);
  const showDetail = config.appEnv !== 'production' && detail !== null;

  return (
    <div className="route-error" role="alert">
      {status !== '' ? <p className="route-error__code">{status}</p> : null}
      <h1 className="route-error__title">This page could not be displayed</h1>
      <p className="route-error__message">
        Something went wrong while loading this screen. Your data has not been changed. Reloading
        often clears it; if it keeps happening, please let support know.
      </p>
      <div className="route-error__actions">
        <button type="button" className="button" onClick={() => window.location.reload()}>
          Reload this page
        </button>
        <Link className="button button--secondary" to="/">
          Back to the catalogue
        </Link>
      </div>

      {showDetail ? (
        <div className="route-error__detail">
          <p>Technical detail (shown outside production only):</p>
          <pre>{detail}</pre>
        </div>
      ) : null}
    </div>
  );
}

/**
 * A developer-facing description, or null when there is nothing useful to say.
 *
 * Never rendered in production — see `showDetail` above.
 */
function describeForDeveloper(error: unknown): string | null {
  if (isRouteErrorResponse(error)) {
    return `${String(error.status)} ${error.statusText}`;
  }
  if (error instanceof Error) {
    return error.stack ?? error.message;
  }
  if (typeof error === 'string') {
    return error;
  }
  return null;
}
