import { Link } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';

/**
 * The branded "no such page" experience (M48, F-10).
 *
 * Split in two deliberately. `NotFoundContent` is standalone markup that needs
 * nothing but router context, so the error boundary can render it even when
 * the failure came from the application chrome itself. `NotFoundPage` wraps
 * that content in `Layout`, which is the better recovery for an ordinary
 * mistyped URL: the user gets the whole navigation back, not just two links.
 */
export function NotFoundContent(): React.JSX.Element {
  return (
    <div className="route-error">
      <p className="route-error__code">404</p>
      <h1 className="route-error__title">We could not find that page</h1>
      <p className="route-error__message">
        The link may be out of date, or the address may have a typo in it. Nothing has gone wrong
        with your account.
      </p>
      <div className="route-error__actions">
        <Link className="button" to="/">
          Back to the catalogue
        </Link>
        <Link className="button button--secondary" to="/search">
          Search instead
        </Link>
      </div>
    </div>
  );
}

/** Rendered by the catch-all route for any address the router does not know. */
export function NotFoundPage(): React.JSX.Element {
  return (
    <Layout>
      <NotFoundContent />
    </Layout>
  );
}
