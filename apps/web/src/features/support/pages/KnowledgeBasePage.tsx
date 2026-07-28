import { useCallback, useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { supportApi } from '../supportApi';
import type { Article } from '../types';

/** The public knowledge base: search help articles, read them, and vote helpfulness. */
export function KnowledgeBasePage(): React.JSX.Element {
  const [term, setTerm] = useState('');
  const [articles, setArticles] = useState<Article[]>([]);
  const [active, setActive] = useState<Article | null>(null);

  const refresh = useCallback((): void => {
    supportApi
      .articles(term)
      .then((page) => setArticles(page.data))
      .catch(() => setArticles([]));
  }, [term]);

  useEffect(refresh, [refresh]);

  const open = (slug: string): void => {
    supportApi
      .article(slug)
      .then(setActive)
      .catch(() => setActive(null));
  };

  const vote = (helpful: boolean): void => {
    if (active === null) return;
    supportApi
      .voteArticle(active.slug, helpful)
      .then(setActive)
      .catch(() => undefined);
  };

  return (
    <Layout>
      <h1>Help centre</h1>
      <form
        className="admin-filters"
        onSubmit={(e) => {
          e.preventDefault();
          refresh();
        }}
      >
        <input value={term} onChange={(e) => setTerm(e.target.value)} placeholder="Search help articles" aria-label="Search articles" />
      </form>

      <div className="support-portal">
        <section className="support-portal__side">
          <ul className="support-ticket-list">
            {articles.length === 0 ? (
              <li className="muted">No articles found.</li>
            ) : (
              articles.map((a) => (
                <li key={a.id}>
                  <button className={`support-ticket-item${active?.id === a.id ? ' is-active' : ''}`} onClick={() => open(a.slug)}>
                    <span className="support-ticket-item__subject">{a.title}</span>
                    <span className="badge badge--normal">{a.category}</span>
                  </button>
                </li>
              ))
            )}
          </ul>
        </section>

        <section className="support-portal__detail">
          {active === null ? (
            <p className="muted">Select an article.</p>
          ) : (
            <>
              <h2>{active.title}</h2>
              <p className="muted">
                {active.category} · v{active.version}
              </p>
              <div className="kb-body">{active.body}</div>
              <div className="support-actions">
                <span>Was this helpful?</span>
                <button className="button button--secondary" onClick={() => vote(true)}>
                  👍 {active.helpful_yes}
                </button>
                <button className="button button--secondary" onClick={() => vote(false)}>
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
