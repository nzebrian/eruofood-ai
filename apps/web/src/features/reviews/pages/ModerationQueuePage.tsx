import { useCallback, useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { reviewsApi } from '../reviewsApi';
import type { Review } from '../types';

/** Moderator workspace: work the queue of held reviews and approve/reject/remove. */
export function ModerationQueuePage(): React.JSX.Element {
  const [queue, setQueue] = useState<Review[]>([]);
  const [reason, setReason] = useState('');
  const [busyId, setBusyId] = useState<string | null>(null);

  const refresh = useCallback((): void => {
    reviewsApi
      .queue()
      .then((page) => setQueue(page.data))
      .catch(() => setQueue([]));
  }, []);

  useEffect(refresh, [refresh]);

  const act = (id: string, run: () => Promise<unknown>): void => {
    setBusyId(id);
    run()
      .then(() => refresh())
      .catch(() => undefined)
      .finally(() => setBusyId(null));
  };

  return (
    <Layout>
      <h1>Review moderation</h1>
      <p className="muted">{queue.length} awaiting review</p>

      <ul className="moderation-queue">
        {queue.length === 0 ? (
          <li className="muted">Queue is clear.</li>
        ) : (
          queue.map((r) => (
            <li key={r.id} className="moderation-item">
              <div className="moderation-item__head">
                <span className="badge badge--pending">{r.rating}★</span>
                <span className="muted">
                  {r.subject_type}:{r.subject_id}
                </span>
                {r.moderation_reason != null && (
                  <span className="badge badge--flagged">{r.moderation_reason}</span>
                )}
              </div>
              {r.title !== null && <h3>{r.title}</h3>}
              {r.body !== null && <p>{r.body}</p>}
              <div className="moderation-item__actions">
                <Button busy={busyId === r.id} onClick={() => act(r.id, () => reviewsApi.approve(r.id))}>
                  Approve
                </Button>
                <button
                  className="button button--secondary"
                  onClick={() => act(r.id, () => reviewsApi.reject(r.id, reason || 'Rejected by moderator'))}
                >
                  Reject
                </button>
              </div>
            </li>
          ))
        )}
      </ul>

      <label className="moderation-reason">
        Rejection reason
        <input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Optional reason" />
      </label>
    </Layout>
  );
}
