import { useCallback, useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { adminApi } from '../adminApi';
import { CONTENT_TYPES, type CmsPageItem } from '../types';

/** Content Management: list managed pages and author/publish new content. */
export function ContentManagerPage(): React.JSX.Element {
  const [typeFilter, setTypeFilter] = useState('');
  const [pages, setPages] = useState<CmsPageItem[]>([]);
  const [loading, setLoading] = useState(false);

  const [type, setType] = useState<string>('page');
  const [title, setTitle] = useState('');
  const [body, setBody] = useState('');
  const [saving, setSaving] = useState(false);

  const refresh = useCallback((): void => {
    setLoading(true);
    adminApi
      .pages(typeFilter, '', 1)
      .then((page) => setPages(page.data))
      .catch(() => setPages([]))
      .finally(() => setLoading(false));
  }, [typeFilter]);

  useEffect(refresh, [refresh]);

  const create = (e: React.FormEvent): void => {
    e.preventDefault();
    if (title.trim() === '' || body.trim() === '') return;
    setSaving(true);
    adminApi
      .createPage({ type, title, body })
      .then(() => {
        setTitle('');
        setBody('');
        refresh();
      })
      .catch(() => undefined)
      .finally(() => setSaving(false));
  };

  const act = (id: string, published: boolean): void => {
    const call = published ? adminApi.archivePage(id) : adminApi.publishPage(id);
    call.then(refresh).catch(() => undefined);
  };

  return (
    <Layout>
      <h1>Content Management</h1>

      <form className="admin-editor" onSubmit={create}>
        <h2>New content</h2>
        <div className="admin-editor__row">
          <select value={type} onChange={(e) => setType(e.target.value)} aria-label="Content type">
            {CONTENT_TYPES.map((t) => (
              <option key={t} value={t}>
                {t}
              </option>
            ))}
          </select>
          <input
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            placeholder="Title"
            aria-label="Title"
          />
        </div>
        <textarea
          value={body}
          onChange={(e) => setBody(e.target.value)}
          placeholder="Body (Markdown or HTML)"
          aria-label="Body"
          rows={6}
        />
        <Button type="submit" busy={saving}>
          Save draft
        </Button>
      </form>

      <div className="admin-filters">
        <select value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)} aria-label="Filter type">
          <option value="">All types</option>
          {CONTENT_TYPES.map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </select>
      </div>

      {loading ? (
        <p className="muted">Loading…</p>
      ) : pages.length === 0 ? (
        <p className="muted">No content yet.</p>
      ) : (
        <table className="admin-table">
          <thead>
            <tr>
              <th>Title</th>
              <th>Type</th>
              <th>Slug</th>
              <th>Status</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {pages.map((p) => (
              <tr key={p.id}>
                <td>{p.title}</td>
                <td>{p.type}</td>
                <td>{p.slug}</td>
                <td>
                  <span className={`badge badge--${p.status}`}>{p.status}</span>
                </td>
                <td>
                  <button
                    className="button button--secondary"
                    onClick={() => act(p.id, p.status === 'published')}
                  >
                    {p.status === 'published' ? 'Archive' : 'Publish'}
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Layout>
  );
}
