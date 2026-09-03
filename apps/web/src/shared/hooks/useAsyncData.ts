import { useCallback, useEffect, useRef, useState } from 'react';
import { ApiRequestError } from '@lib/apiClient';

/**
 * The three states an asynchronous read can be in, as one value.
 *
 * ## Why this exists (M48, F-09)
 *
 * Before M48, 43 of the 50 web pages fetched with this shape:
 *
 * ```ts
 * api.list().then(setItems).catch(() => setItems([]));
 * ```
 *
 * That is not error handling — it is error *erasure*. A 500, an expired
 * session and a genuinely empty collection all arrived at the same screen:
 * "No rewards available right now." The user is told a fact about their
 * account that is actually a fact about the server being down, and there is
 * nothing to retry because nothing looks broken.
 *
 * Modelling the three states as a discriminated union makes the mistake
 * unrepresentable: a page cannot render "empty" without having first
 * distinguished it from "loading" and "failed", because the compiler makes it
 * narrow the union before it can reach `data`.
 */
export type AsyncData<T> =
  | { readonly status: 'loading' }
  | { readonly status: 'error'; readonly message: string }
  | { readonly status: 'ready'; readonly data: T };

/**
 * Turn a thrown value into something worth showing a person.
 *
 * The API's error envelope carries a message written for end users, so an
 * {@link ApiRequestError} is quoted directly. Everything else is replaced:
 * a failed `fetch` surfaces as `TypeError: Failed to fetch`, which tells the
 * user nothing and leaks the transport, and an arbitrary `Error` may carry
 * internal detail. No stack is ever rendered.
 */
export function describeError(
  error: unknown,
  fallback = 'Something went wrong. Please try again.',
): string {
  if (error instanceof ApiRequestError) {
    return error.error.message;
  }
  if (error instanceof TypeError) {
    return 'Could not reach the server. Check your connection and try again.';
  }
  return fallback;
}

export interface AsyncResult<T> {
  /** The current state. Narrow on `status` before reading `data`. */
  readonly state: AsyncData<T>;
  /** Re-run the loader. Wire this to an error state's retry action. */
  readonly reload: () => void;
  /** Replace the loaded value locally, e.g. after a successful write. */
  readonly setData: (next: T) => void;
  /** Derive a new loaded value from the current one. No-op unless ready. */
  readonly updateData: (derive: (previous: T) => T) => void;
}

/**
 * Run an asynchronous read and expose it as a {@link AsyncData}.
 *
 * `key` — not a dependency array — is what re-triggers the load. A string is
 * used deliberately: a spread dependency array (`[...deps]`) is something
 * `react-hooks/exhaustive-deps` cannot verify, and this repository lints with
 * `--max-warnings=0`, so the alternative would have been to suppress the rule
 * on every call site. A caller composes its inputs instead:
 *
 * ```ts
 * useAsyncData(() => catalogApi.recipes({ q, sort }), `recipes|${q}|${sort}`);
 * ```
 *
 * The loader is held in a ref so that a caller need not memoise it. The ref is
 * synchronised by an effect declared *before* the fetching effect, and React
 * runs effect bodies in declaration order, so the fetch always sees the
 * closure from the render that changed the key.
 *
 * In-flight results are discarded when the key changes or the component
 * unmounts, so a slow first request cannot overwrite a fast second one.
 */
export function useAsyncData<T>(load: () => Promise<T>, key = ''): AsyncResult<T> {
  const [state, setState] = useState<AsyncData<T>>({ status: 'loading' });
  const [nonce, setNonce] = useState(0);

  const loadRef = useRef(load);
  useEffect(() => {
    loadRef.current = load;
  });

  useEffect(() => {
    let cancelled = false;
    setState({ status: 'loading' });

    loadRef.current().then(
      (data) => {
        if (!cancelled) setState({ status: 'ready', data });
      },
      (error: unknown) => {
        if (!cancelled) setState({ status: 'error', message: describeError(error) });
      },
    );

    return () => {
      cancelled = true;
    };
  }, [key, nonce]);

  const reload = useCallback(() => {
    setNonce((n) => n + 1);
  }, []);

  const setData = useCallback((next: T) => {
    setState({ status: 'ready', data: next });
  }, []);

  const updateData = useCallback((derive: (previous: T) => T) => {
    setState((current) =>
      current.status === 'ready' ? { status: 'ready', data: derive(current.data) } : current,
    );
  }, []);

  return { state, reload, setData, updateData };
}
