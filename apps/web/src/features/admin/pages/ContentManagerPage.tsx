import { useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { AsyncView, EmptyState, ErrorState } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { adminApi } from '../adminApi';
import { CONTENT_TYPES } from '../types';

/** Content Management: list managed pages and author/publish new content. */
export function ContentManagerPage(): React.JSX.Element {
  const [typeFilter, setTypeFilter] = useState('');
  const [type, setType] = useState<string>('page');
  const [title, setTitle] = useState('');
  const [body, setBody] = useState('');
  const [saving, setSaving] = useState(false);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const pages = useAsyncData(
    () => adminApi.pages(typeFilter, '', 1),
    `admin|cms-pages|${typeFilter}`,
  );

  function create(e: React.FormEvent): void {
    e.preventDefault();
    if (title.trim() === '' || body.trim() === '') return;
    setSaving(true);
    setActionError(null);
    adminApi
      .createPage({ type, title, body })
      .then(() => {
        setTitle('');
        setBody('');
        pages.reload();
      })
      .catch((err: unknown) =>
        // `.catch(() => undefined)` meant a draft that failed to save cleared
        // the editor anyway, losing the author's work without a word.
        setActionError(describeError(err, 'Your draft was not saved.')),
      )
      .finally(() => setSaving(false));
  }

  function act(id: string, published: boolean): void {
    setBusyId(id);
    setActionError(null);
    (published ? adminApi.archivePage(id) : adminApi.publishPage(id))
      .then(() => pages.reload())
      .catch((err: unknown) =>
        setActionError(
          describeError(
            err,
            published ? 'Could not archive that page.' : 'Could not publish that page.',
          ),
        ),
      )
      .finally(() => setBusyId(null));
  }

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
            aria-label="Content title"
          />
        </div>
        <textarea
          value={body}
          onChange={(e) => setBody(e.target.value)}
          placeholder="Body (Markdown or HTML)"
          aria-label="Content body"
          rows={6}
        />
        <Button type="submit" busy={saving}>
          Save draft
        </Button>
      </form>

      {actionError !== null ? <ErrorState message={actionError} title="That did not work" /> : null}

      <div className="admin-filters">
        <select
          value={typeFilter}
          onChange={(e) => setTypeFilter(e.target.value)}
          aria-label="Filter by content type"
        >
          <option value="">All types</option>
          {CONTENT_TYPES.map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </select>
      </div>

      <AsyncView
        state={pages.state}
        loadingLabel="Loading content…"
        errorTitle="We could not load the content list"
        onRetry={pages.reload}
      >
        {(page) =>
          page.data.length === 0 ? (
            <EmptyState
              title={typeFilter === '' ? 'No content yet' : `No ${typeFilter} content yet`}
              description="Author something with the editor above and it will appear here."
            />
          ) : (
            <div className="table-scroll">
              <table className="admin-table">
                <caption className="sr-only">Managed content</caption>
                <thead>
                  <tr>
                    <th scope="col">Title</th>
                    <th scope="col">Type</th>
                    <th scope="col">Slug</th>
                    <th scope="col">Status</th>
                    <th scope="col">
                      <span className="sr-only">Actions</span>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {page.data.map((p) => (
                    <tr key={p.id}>
                      <td>{p.title}</td>
                      <td>{p.type}</td>
                      <td className="break-anywhere">{p.slug}</td>
                      <td>
                        <span className={`badge badge--${p.status}`}>{p.status}</span>
                      </td>
                      <td>
                        <Button
                          className="button--secondary"
                          busy={busyId === p.id}
                          onClick={() => act(p.id, p.status === 'published')}
                        >
                          {p.status === 'published' ? 'Archive' : 'Publish'}
                          <span className="sr-only"> {p.title}</span>
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )
        }
      </AsyncView>
    </Layout>
  );
}
