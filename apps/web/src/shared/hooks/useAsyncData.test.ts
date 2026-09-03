import { act, renderHook, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ApiRequestError } from '@lib/apiClient';
import { describeError, useAsyncData } from './useAsyncData';

describe('describeError', () => {
  it('quotes the API envelope message, which is written for end users', () => {
    const error = new ApiRequestError(422, {
      code: 'INVALID_ARGUMENT',
      message: 'Phone must be E.164.',
    });

    expect(describeError(error)).toBe('Phone must be E.164.');
  });

  it('replaces a failed fetch with something a person can act on', () => {
    // `fetch` rejects with a TypeError on a network failure, whose message is
    // "Failed to fetch" — accurate, and useless to the person reading it.
    expect(describeError(new TypeError('Failed to fetch'))).toBe(
      'Could not reach the server. Check your connection and try again.',
    );
  });

  it('never surfaces an arbitrary error message or stack', () => {
    const error = new Error('Cannot read properties of undefined (reading _internal)');

    expect(describeError(error)).toBe('Something went wrong. Please try again.');
  });

  it('accepts a caller fallback', () => {
    expect(describeError(null, 'Could not load your wallet.')).toBe('Could not load your wallet.');
  });
});

describe('useAsyncData', () => {
  it('starts loading and resolves to ready', async () => {
    const { result } = renderHook(() => useAsyncData(() => Promise.resolve(['Jollof'])));

    expect(result.current.state.status).toBe('loading');

    await waitFor(() => {
      expect(result.current.state).toEqual({ status: 'ready', data: ['Jollof'] });
    });
  });

  it('resolves to error rather than to an empty value', async () => {
    const { result } = renderHook(() =>
      useAsyncData(() =>
        Promise.reject(new ApiRequestError(500, { code: 'SERVER_ERROR', message: 'Try later.' })),
      ),
    );

    await waitFor(() => {
      expect(result.current.state).toEqual({ status: 'error', message: 'Try later.' });
    });
  });

  it('re-runs the loader on reload', async () => {
    const load = vi.fn<() => Promise<number>>().mockResolvedValueOnce(1).mockResolvedValueOnce(2);

    const { result } = renderHook(() => useAsyncData(load));
    await waitFor(() => {
      expect(result.current.state).toEqual({ status: 'ready', data: 1 });
    });

    act(() => {
      result.current.reload();
    });

    await waitFor(() => {
      expect(result.current.state).toEqual({ status: 'ready', data: 2 });
    });
    expect(load).toHaveBeenCalledTimes(2);
  });

  it('re-runs when the key changes and uses the latest loader closure', async () => {
    const { result, rerender } = renderHook(
      ({ q }: { q: string }) =>
        useAsyncData(() => Promise.resolve(`results for ${q}`), `search|${q}`),
      { initialProps: { q: 'jollof' } },
    );

    await waitFor(() => {
      expect(result.current.state).toEqual({ status: 'ready', data: 'results for jollof' });
    });

    rerender({ q: 'egusi' });

    await waitFor(() => {
      expect(result.current.state).toEqual({ status: 'ready', data: 'results for egusi' });
    });
  });

  it('discards a stale in-flight result so a slow first load cannot overwrite a fast second', async () => {
    let resolveSlow: ((value: string) => void) | undefined;
    const slow = new Promise<string>((resolve) => {
      resolveSlow = resolve;
    });

    const { result, rerender } = renderHook(
      ({ q }: { q: string }) =>
        useAsyncData(() => (q === 'slow' ? slow : Promise.resolve('fast')), `k|${q}`),
      { initialProps: { q: 'slow' } },
    );

    rerender({ q: 'fast' });
    await waitFor(() => {
      expect(result.current.state).toEqual({ status: 'ready', data: 'fast' });
    });

    // The first request finally answers. It must be ignored.
    await act(async () => {
      resolveSlow?.('slow');
      await slow;
    });

    expect(result.current.state).toEqual({ status: 'ready', data: 'fast' });
  });

  it('replaces and derives the loaded value locally', async () => {
    const { result } = renderHook(() => useAsyncData(() => Promise.resolve([1, 2])));
    await waitFor(() => {
      expect(result.current.state.status).toBe('ready');
    });

    act(() => {
      result.current.setData([3]);
    });
    expect(result.current.state).toEqual({ status: 'ready', data: [3] });

    act(() => {
      result.current.updateData((previous) => [...previous, 4]);
    });
    expect(result.current.state).toEqual({ status: 'ready', data: [3, 4] });
  });

  it('ignores a local update while the value is not loaded', async () => {
    const { result } = renderHook(() =>
      useAsyncData<number[]>(() => Promise.reject(new TypeError('x'))),
    );
    await waitFor(() => {
      expect(result.current.state.status).toBe('error');
    });

    act(() => {
      result.current.updateData((previous) => previous);
    });

    expect(result.current.state.status).toBe('error');
  });
});
