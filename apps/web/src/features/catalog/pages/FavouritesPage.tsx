import { Link } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { AsyncView, EmptyState } from '@shared/components/StateViews';
import { useAsyncData } from '@shared/hooks/useAsyncData';
import { catalogApi } from '../catalogApi';

export function FavouritesPage(): React.JSX.Element {
  const favourites = useAsyncData(() => catalogApi.favourites(), 'catalog|favourites');

  return (
    <Layout>
      <h1>My favourite recipes</h1>

      <AsyncView
        state={favourites.state}
        loadingLabel="Loading your favourites…"
        errorTitle="We could not load your favourites"
        onRetry={favourites.reload}
      >
        {(page) =>
          page.data.length === 0 ? (
            <EmptyState
              title="No favourites yet"
              description="Save a recipe and it will appear here."
              action={
                <Link className="button button--secondary" to="/recipes">
                  Browse recipes
                </Link>
              }
            />
          ) : (
            <ul className="list">
              {page.data.map((r) => (
                <li key={r.id}>
                  <Link to={`/recipes/${r.slug}`}>{r.title}</Link>
                </li>
              ))}
            </ul>
          )
        }
      </AsyncView>
    </Layout>
  );
}
