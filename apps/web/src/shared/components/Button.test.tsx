import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { Button } from './Button';

/**
 * M48 / F-03.
 *
 * The defect these tests exist for was invisible to `tsc` and to ESLint —
 * spreading `{...rest}` after the component's own props is valid TypeScript
 * that silently discards them. Nothing rendered this component in a test, so
 * it shipped. Each case below is one of the two overrides, or one of the
 * behaviours that must survive the fix.
 */
describe('Button', () => {
  it('renders its children on a button element carrying the base class', () => {
    render(<Button>Redeem</Button>);

    const button = screen.getByRole('button', { name: 'Redeem' });
    expect(button).toBeInTheDocument();
    expect(button).toHaveClass('button');
    expect(button).toBeEnabled();
    expect(button).not.toHaveAttribute('aria-busy');
  });

  it('combines a caller className with the base class instead of replacing it', () => {
    render(<Button className="button--secondary">Mark all read</Button>);

    const button = screen.getByRole('button', { name: 'Mark all read' });
    // Both, in that order. `.button--secondary` supplies only colours; every
    // geometric property lives on `.button`, so losing the base class renders
    // an unstyled browser default with a green outline.
    expect(button).toHaveClass('button');
    expect(button).toHaveClass('button--secondary');
  });

  it('honours a caller-supplied disabled', () => {
    render(<Button disabled>Redeem</Button>);

    expect(screen.getByRole('button', { name: 'Redeem' })).toBeDisabled();
  });

  it('disables itself and announces aria-busy while busy', () => {
    render(<Button busy>Redeem</Button>);

    const button = screen.getByRole('button', { name: 'Please wait…' });
    expect(button).toBeDisabled();
    expect(button).toHaveAttribute('aria-busy', 'true');
  });

  it('stays disabled when busy even though the caller passes disabled={false}', () => {
    // The exact LoyaltyPage shape: `busy` and `disabled` are computed from
    // different things, and for an affordable reward the caller's `false`
    // used to win — leaving a button that read "Please wait…" and was still
    // clickable.
    render(
      <Button busy disabled={false}>
        Redeem
      </Button>,
    );

    expect(screen.getByRole('button', { name: 'Please wait…' })).toBeDisabled();
  });

  it('stays disabled when the caller disables it and it is not busy', () => {
    render(
      <Button busy={false} disabled>
        Redeem
      </Button>,
    );

    expect(screen.getByRole('button', { name: 'Redeem' })).toBeDisabled();
  });

  it('does not invoke onClick while busy, even for a dispatched click event', async () => {
    const onClick = vi.fn();
    render(
      <Button busy disabled={false} onClick={onClick}>
        Redeem
      </Button>,
    );

    const button = screen.getByRole('button', { name: 'Please wait…' });

    // `userEvent` refuses to click a disabled control, which is the browser's
    // behaviour and the first line of defence.
    await userEvent.click(button);
    expect(onClick).not.toHaveBeenCalled();

    // `fireEvent` dispatches regardless, which is what proves the handler
    // guard rather than only the `disabled` attribute.
    fireEvent.click(button);
    expect(onClick).not.toHaveBeenCalled();
  });

  it('invokes onClick exactly once when it is not busy', async () => {
    const onClick = vi.fn();
    render(<Button onClick={onClick}>Redeem</Button>);

    await userEvent.click(screen.getByRole('button', { name: 'Redeem' }));

    expect(onClick).toHaveBeenCalledTimes(1);
  });

  it('preserves the button attributes callers rely on', () => {
    render(
      <Button type="submit" name="redeem" value="42" aria-label="Redeem reward" title="Redeem">
        Redeem
      </Button>,
    );

    const button = screen.getByRole('button', { name: 'Redeem reward' });
    expect(button).toHaveAttribute('type', 'submit');
    expect(button).toHaveAttribute('name', 'redeem');
    expect(button).toHaveAttribute('value', '42');
    expect(button).toHaveAttribute('title', 'Redeem');
  });
});
