import type { ButtonHTMLAttributes } from 'react';

/** Primary button with a busy state. */
export function Button({
  children,
  busy,
  ...rest
}: ButtonHTMLAttributes<HTMLButtonElement> & { busy?: boolean }): React.JSX.Element {
  return (
    <button className="button" disabled={busy || rest.disabled} {...rest}>
      {busy ? 'Please wait…' : children}
    </button>
  );
}
