import { useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { AsyncView, EmptyState, ErrorState } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { reviewsApi } from '../reviewsApi';

/** Moderator workspace: work the queue of held reviews and approve/reject/remove. */
export function ModerationQueuePage(): React.JSX.Element {
  const [reason, setReason] = useState('');
  const [busyId, setBusyId] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  // `.catch(() => setQueue([]))` printed "Queue is clear." whenever the queue
  // could not be fetched. On a moderation queue that is not a cosmetic
  // problem: it tells the moderator there is no held content to look at.
  const queue = useAsyncData(() => reviewsApi.queue(), 'reviews|queue');

  function act(id: string, run: () => Promise<unknown>, failure: string): void {
    setBusyId(id);
    setActionError(null);
    run()
      .then(() => queue.reload())
      .catch((err: unknown) => setActionError(describeError(err, failure)))
      .finally(() => setBusyId(null));
  }

  return (
    <Layout>
      <h1>Review moderation</h1>

      {actionError !== null ? (
        <ErrorState message={actionError} title="That moderation action failed" />
      ) : null}

      <label className="moderation-reason">
        <span className="field__label">Rejection reason</span>
        <input
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          placeholder="Optional reason"
        />
      </label>

      <AsyncView
        state={queue.state}
        loadingLabel="Loading the moderation queue…"
        errorTitle="We could not load the moderation queue"
        onRetry={queue.reload}
      >
        {(page) => (
          <>
            <p className="muted">{page.data.length} awaiting review</p>

            {page.data.length === 0 ? (
              <EmptyState
                title="Queue is clear"
                description="Nothing is currently held for moderation."
              />
            ) : (
              <ul className="moderation-queue">
                {page.data.map((r) => (
                  <li key={r.id} className="moderation-item">
                    <div className="moderation-item__head">
                      <span className="badge badge--pending">{r.rating}★</span>
                      <span className="muted break-anywhere">
                        {r.subject_type}:{r.subject_id}
                      </span>
                      {r.moderation_reason != null && (
                        <span className="badge badge--flagged">{r.moderation_reason}</span>
                      )}
                    </div>
                    {r.title !== null && <h2 className="moderation-item__title">{r.title}</h2>}
                    {r.body !== null && <p>{r.body}</p>}
                    <div className="moderation-item__actions row-actions">
                      <Button
                        busy={busyId === r.id}
                        onClick={() =>
                          act(
                            r.id,
                            () => reviewsApi.approve(r.id),
                            'Could not approve that review.',
                          )
                        }
                      >
                        Approve
                      </Button>
                      <Button
                        className="button--secondary"
                        busy={busyId === r.id}
                        onClick={() =>
                          act(
                            r.id,
                            () => reviewsApi.reject(r.id, reason || 'Rejected by moderator'),
                            'Could not reject that review.',
                          )
                        }
                      >
                        Reject
                      </Button>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </>
        )}
      </AsyncView>
    </Layout>
  );
}
