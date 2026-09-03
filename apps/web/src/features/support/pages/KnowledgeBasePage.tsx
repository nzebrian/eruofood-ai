import { useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { AsyncView, EmptyState, ErrorState } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { supportApi } from '../supportApi';
import type { Article } from '../types';

/** The public knowledge base: search help articles, read them, and vote helpfulness. */
export function KnowledgeBasePage(): React.JSX.Element {
  const [term, setTerm] = useState('');
  const [query, setQuery] = useState('');
  const [active, setActive] = useState<Article | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const articles = useAsyncData(() => supportApi.articles(query), `support|articles|${query}`);

  const open = (slug: string): void => {
    setActionError(null);
    supportApi
      .article(slug)
      .then(setActive)
      .catch((err: unknown) => setActionError(describeError(err, 'Could not open that article.')));
  };

  const vote = (helpful: boolean): void => {
    if (active === null) return;
    setActionError(null);
    supportApi
      .voteArticle(active.slug, helpful)
      .then(setActive)
      .catch((err: unknown) => setActionError(describeError(err, 'Could not record your vote.')));
  };

  return (
    <Layout>
      <h1>Help centre</h1>
      <form
        className="admin-filters"
        onSubmit={(e) => {
          e.preventDefault();
          setQuery(term);
        }}
      >
        <input
          value={term}
          onChange={(e) => setTerm(e.target.value)}
          placeholder="Search help articles"
          aria-label="Search help articles"
        />
      </form>

      {actionError !== null ? <ErrorState message={actionError} title="That did not work" /> : null}

      <div className="support-portal">
        <section className="support-portal__side">
          <AsyncView
            state={articles.state}
            loadingLabel="Loading help articles\u2026"
            errorTitle="We could not load the help centre"
            onRetry={articles.reload}
          >
            {(page) =>
              page.data.length === 0 ? (
                <EmptyState
                  title={
                    query === '' ? 'No articles yet' : `No articles match \u201C${query}\u201D`
                  }
                  description={
                    query === ''
                      ? 'Help articles will appear here as they are published.'
                      : 'Try a shorter or different search term.'
                  }
                />
              ) : (
                <ul className="support-ticket-list">
                  {page.data.map((a) => (
                    <li key={a.id}>
                      <button
                        type="button"
                        className={`support-ticket-item${active?.id === a.id ? ' is-active' : ''}`}
                        aria-current={active?.id === a.id ? 'true' : undefined}
                        onClick={() => open(a.slug)}
                      >
                        <span className="support-ticket-item__subject">{a.title}</span>
                        <span className="badge badge--normal">{a.category}</span>
                      </button>
                    </li>
                  ))}
                </ul>
              )
            }
          </AsyncView>
        </section>

        <section className="support-portal__detail">
          {active === null ? (
            <EmptyState title="Select an article to read it" />
          ) : (
            <>
              <h2>{active.title}</h2>
              <p className="muted">
                {active.category} · v{active.version}
              </p>
              <div className="kb-body">{active.body}</div>
              <div className="support-actions">
                <span>Was this helpful?</span>
                <button
                  type="button"
                  className="button button--secondary"
                  onClick={() => vote(true)}
                  aria-label={`Yes, "${active.title}" was helpful`}
                >
                  👍 {active.helpful_yes}
                </button>
                <button
                  type="button"
                  className="button button--secondary"
                  onClick={() => vote(false)}
                  aria-label={`No, "${active.title}" was not helpful`}
                >
                  👎 {active.helpful_no}
                </button>
              </div>
            </>
          )}
        </section>
      </div>
    </Layout>
  );
}
