import type { ReactNode } from 'react';
import type { AsyncData } from '@shared/hooks/useAsyncData';

/**
 * The three shared states every data-backed screen needs, plus the small
 * renderer that picks between them (M48).
 *
 * These are deliberately three plain components and one switch rather than a
 * design system. The repository has one stylesheet and four shared
 * components; adding a component library to fix "the user cannot tell loading
 * from empty" would be a much larger change than the problem warrants.
 *
 * Semantics, and why each is what it is:
 *
 * - `Loading` is `role="status"` with `aria-live="polite"`. A screen reader
 *   announces it when it appears without interrupting whatever the user is
 *   already hearing, which is right for a transient wait.
 * - `ErrorState` is `role="alert"` — assertive, because a failed request has
 *   changed what the user can do and they need to know now.
 * - `EmptyState` carries no ARIA at all. It is ordinary content that happens
 *   to say there is nothing here; announcing it as a status or an alert would
 *   be noise, and this repository's rule is to prefer semantic HTML over
 *   decorative ARIA.
 *
 * Titles render as `<p class="state__title">` rather than headings. These are
 * region-level messages that appear inside pages which already own an `<h1>`
 * and frequently an `<h2>` per section; emitting another heading would break
 * the document outline in a way that is worse for assistive technology than
 * having no heading at all.
 */

/** A transient wait. Give it a label describing what is being fetched. */
export function Loading({ label = 'Loading…' }: { label?: string }): React.JSX.Element {
  return (
    <div className="state state--loading" role="status" aria-live="polite">
      <span className="state__spinner" aria-hidden="true" />
      <p className="state__message">{label}</p>
    </div>
  );
}

/**
 * A failed read or write.
 *
 * `onRetry` is optional because not every failure is retryable, and offering
 * a "Try again" that cannot work is worse than offering nothing.
 */
export function ErrorState({
  title = 'Something went wrong',
  message,
  onRetry,
  retryLabel = 'Try again',
}: {
  title?: string;
  message: string;
  onRetry?: () => void;
  retryLabel?: string;
}): React.JSX.Element {
  return (
    <div className="state state--error" role="alert">
      <p className="state__title">{title}</p>
      <p className="state__message">{message}</p>
      {onRetry ? (
        <button type="button" className="button button--secondary state__action" onClick={onRetry}>
          {retryLabel}
        </button>
      ) : null}
    </div>
  );
}

/** A successful read that returned nothing. Say what would fill it. */
export function EmptyState({
  title,
  description,
  action,
}: {
  title: string;
  description?: string;
  action?: ReactNode;
}): React.JSX.Element {
  return (
    <div className="state state--empty">
      <p className="state__title">{title}</p>
      {description !== undefined ? <p className="state__message">{description}</p> : null}
      {action !== undefined ? <div className="state__action">{action}</div> : null}
    </div>
  );
}

/**
 * Render the loading and error states of an {@link AsyncData}, and hand the
 * loaded value to `children`.
 *
 * Emptiness is deliberately *not* handled here. Whether zero rows means "no
 * search results for jollof", "your cart is empty" or "no tickets are waiting"
 * is a judgement each page has to make, and a generic "No data" would put back
 * the indistinguishable screen this milestone exists to remove.
 */
export function AsyncView<T>({
  state,
  loadingLabel,
  errorTitle,
  onRetry,
  children,
}: {
  state: AsyncData<T>;
  loadingLabel?: string;
  errorTitle?: string;
  onRetry?: () => void;
  children: (data: T) => ReactNode;
}): React.JSX.Element {
  if (state.status === 'loading') {
    return <Loading label={loadingLabel} />;
  }

  if (state.status === 'error') {
    return <ErrorState title={errorTitle} message={state.message} onRetry={onRetry} />;
  }

  return <>{children(state.data)}</>;
}
