import type { ButtonHTMLAttributes, MouseEvent } from 'react';

/**
 * Primary button with a busy state.
 *
 * ## The M48 fix (F-03)
 *
 * This component used to read:
 *
 * ```tsx
 * <button className="button" disabled={busy || rest.disabled} {...rest}>
 * ```
 *
 * `{...rest}` was spread *after* both computed props, so a caller's own
 * `className` or `disabled` silently replaced them. Two live consequences on
 * `main`:
 *
 * - Seven `className="button--secondary"` call sites lost the base `button`
 *   class entirely. `.button--secondary` supplies only colours; every
 *   geometric property — width, padding, radius, font-size, cursor, and the
 *   `:disabled` opacity rule — lives on `.button`. Those buttons rendered as
 *   unstyled browser defaults with a green outline.
 * - `LoyaltyPage` passes `busy={…}` and `disabled={balance < cost}`
 *   independently. For an affordable reward the caller's `false` won, so the
 *   button read "Please wait…" *and stayed clickable* — a double-tap fired a
 *   second redemption of a value-bearing reward.
 *
 * The fix spreads first and computes last, so the component's own invariants
 * cannot be overwritten, while every other caller attribute still passes
 * through. `onClick` is additionally guarded: `disabled` alone is not enough
 * for callers who reach the handler by other means (Enter on a focused
 * element that a future variant renders as a link, a synthetic click in a
 * test), and a busy button must never fire its action twice.
 */
export function Button({
  children,
  busy,
  ...rest
}: ButtonHTMLAttributes<HTMLButtonElement> & { busy?: boolean }): React.JSX.Element {
  const disabled = Boolean(busy) || Boolean(rest.disabled);

  function handleClick(event: MouseEvent<HTMLButtonElement>): void {
    // Belt and braces with `disabled` below. A busy button firing its handler
    // a second time is the defect this component shipped with; refusing the
    // event here means the guarantee does not depend on the caller's props.
    if (disabled) {
      event.preventDefault();
      return;
    }
    rest.onClick?.(event);
  }

  return (
    <button
      {...rest}
      className={['button', rest.className].filter(Boolean).join(' ')}
      disabled={disabled}
      aria-busy={busy ? true : undefined}
      onClick={handleClick}
    >
      {busy ? 'Please wait…' : children}
    </button>
  );
}
