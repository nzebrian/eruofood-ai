import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import type { AsyncData } from '@shared/hooks/useAsyncData';
import { AsyncView, EmptyState, ErrorState, Loading } from './StateViews';

describe('Loading', () => {
  it('announces politely as a status with a default label', () => {
    render(<Loading />);

    const status = screen.getByRole('status');
    expect(status).toHaveTextContent('Loading…');
    expect(status).toHaveAttribute('aria-live', 'polite');
  });

  it('uses the caller label so the wait says what is being fetched', () => {
    render(<Loading label="Loading recipes…" />);

    expect(screen.getByRole('status')).toHaveTextContent('Loading recipes…');
  });
});

describe('ErrorState', () => {
  it('renders as an alert with a default title and the given message', () => {
    render(<ErrorState message="The kitchen is closed." />);

    const alert = screen.getByRole('alert');
    expect(alert).toHaveTextContent('Something went wrong');
    expect(alert).toHaveTextContent('The kitchen is closed.');
  });

  it('offers no retry control when the failure is not retryable', () => {
    render(<ErrorState message="The kitchen is closed." />);

    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });

  it('invokes onRetry when the retry control is used', async () => {
    const onRetry = vi.fn();
    render(<ErrorState message="Could not load rewards." onRetry={onRetry} />);

    await userEvent.click(screen.getByRole('button', { name: 'Try again' }));

    expect(onRetry).toHaveBeenCalledTimes(1);
  });
});

describe('EmptyState', () => {
  it('states what is missing and carries no status or alert semantics', () => {
    render(<EmptyState title="No rewards yet" description="Earn points to unlock rewards." />);

    expect(screen.getByText('No rewards yet')).toBeInTheDocument();
    expect(screen.getByText('Earn points to unlock rewards.')).toBeInTheDocument();
    // Emptiness is ordinary content, not an event to announce.
    expect(screen.queryByRole('status')).not.toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('renders an optional call to action', () => {
    render(<EmptyState title="Your cart is empty" action={<a href="/shop">Start shopping</a>} />);

    expect(screen.getByRole('link', { name: 'Start shopping' })).toBeInTheDocument();
  });
});

describe('AsyncView', () => {
  const children = (data: string[]): React.JSX.Element => <p>{data.join(', ')}</p>;

  it('shows the loading state and never the content', () => {
    const state: AsyncData<string[]> = { status: 'loading' };
    render(
      <AsyncView state={state} loadingLabel="Loading orders…">
        {children}
      </AsyncView>,
    );

    expect(screen.getByRole('status')).toHaveTextContent('Loading orders…');
  });

  it('shows the error state, and does not fall back to an empty-looking screen', () => {
    const state: AsyncData<string[]> = { status: 'error', message: 'Could not load orders.' };
    const onRetry = vi.fn();
    render(
      <AsyncView state={state} onRetry={onRetry}>
        {children}
      </AsyncView>,
    );

    // The whole point of the milestone: a failure is visibly a failure, not
    // an empty list that reads as "you have no orders".
    expect(screen.getByRole('alert')).toHaveTextContent('Could not load orders.');
    expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument();
  });

  it('renders the children with the loaded value once ready', () => {
    const state: AsyncData<string[]> = { status: 'ready', data: ['Jollof', 'Egusi'] };
    render(<AsyncView state={state}>{children}</AsyncView>);

    expect(screen.getByText('Jollof, Egusi')).toBeInTheDocument();
    expect(screen.queryByRole('status')).not.toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });
});
