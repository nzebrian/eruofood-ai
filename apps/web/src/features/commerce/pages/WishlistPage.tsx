import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { AsyncView, EmptyState, ErrorState } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { commerceApi } from '../commerceApi';
import { formatMoney } from '../types';

/** The shopper's saved-for-later products. */
export function WishlistPage(): React.JSX.Element {
  const [busyId, setBusyId] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const wishlist = useAsyncData(() => commerceApi.wishlist(), 'commerce|wishlist');

  async function remove(productId: string): Promise<void> {
    setBusyId(productId);
    setActionError(null);
    try {
      await commerceApi.removeFromWishlist(productId);
      wishlist.reload();
    } catch (err) {
      setActionError(describeError(err, 'Could not remove that item.'));
    } finally {
      setBusyId(null);
    }
  }

  return (
    <Layout>
      <h1>Your wishlist</h1>

      {actionError !== null ? <ErrorState message={actionError} title="That did not work" /> : null}

      <AsyncView
        state={wishlist.state}
        loadingLabel="Loading your wishlist…"
        errorTitle="We could not load your wishlist"
        onRetry={wishlist.reload}
      >
        {(saved) =>
          saved.products.length === 0 ? (
            <EmptyState
              title="Nothing saved yet"
              description="Tap ♡ Save on a product to keep it for later."
              action={
                <Link className="button button--secondary" to="/shop">
                  Browse the shop
                </Link>
              }
            />
          ) : (
            <ul className="list">
              {saved.products.map((p) => (
                <li key={p.id} className="commerce-cart__row">
                  <Link to={`/shop/${p.slug}`}>{p.name}</Link>
                  <span>{formatMoney(p.base_price_minor, p.currency)}</span>
                  <Button
                    className="button--secondary"
                    busy={busyId === p.id}
                    onClick={() => void remove(p.id)}
                  >
                    Remove
                    <span className="sr-only"> {p.name} from your wishlist</span>
                  </Button>
                </li>
              ))}
            </ul>
          )
        }
      </AsyncView>
    </Layout>
  );
}
