import type { InputHTMLAttributes } from 'react';

interface FormFieldProps extends InputHTMLAttributes<HTMLInputElement> {
  label: string;
  name: string;
}

/** Labelled text input used across the auth forms. */
export function FormField({ label, name, ...rest }: FormFieldProps): React.JSX.Element {
  return (
    <label className="field">
      <span className="field__label">{label}</span>
      <input className="field__input" name={name} id={name} {...rest} />
    </label>
  );
}
