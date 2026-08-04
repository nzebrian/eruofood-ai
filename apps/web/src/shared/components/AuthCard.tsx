import type { ReactNode } from 'react';

/** Centered card wrapper shared by the auth pages. */
export function AuthCard({
  title,
  subtitle,
  children,
}: {
  title: string;
  subtitle?: string;
  children: ReactNode;
}): React.JSX.Element {
  return (
    <div className="auth">
      <div className="auth__card">
        <h1 className="auth__title">{title}</h1>
        {subtitle ? <p className="auth__subtitle">{subtitle}</p> : null}
        {children}
      </div>
    </div>
  );
}

export function ErrorText({ message }: { message: string | null }): React.JSX.Element | null {
  return message ? <p className="auth__error">{message}</p> : null;
}
