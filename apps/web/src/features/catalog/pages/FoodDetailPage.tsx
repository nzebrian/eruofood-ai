import { Link, useParams } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { AsyncView, EmptyState } from '@shared/components/StateViews';
import { useAsyncData } from '@shared/hooks/useAsyncData';
import { catalogApi } from '../catalogApi';

export function FoodDetailPage(): React.JSX.Element {
  const { slug = '' } = useParams();

  // Both reads belong to one screen, so they are one loader: the recipe list
  // needs the food's id, and a page that has the food but not its recipes is
  // not a state worth rendering.
  const detail = useAsyncData(async () => {
    const food = await catalogApi.food(slug);
    const recipes = await catalogApi.recipesForFood(food.id);
    return { food, recipes: recipes.data };
  }, `catalog|food|${slug}`);

  return (
    <Layout>
      <AsyncView
        state={detail.state}
        loadingLabel="Loading this food…"
        errorTitle="We could not load this food"
        onRetry={detail.reload}
      >
        {({ food, recipes }) => (
          <>
            <h1>{food.name}</h1>
            {food.local_names.length > 0 && (
              <p className="muted">
                Also known as: {food.local_names.map((l) => `${l.name} (${l.language})`).join(', ')}
              </p>
            )}
            <p className="muted">
              {food.region.replace(/_/g, ' ')}
              {food.states.length > 0 ? ` · ${food.states.join(', ')}` : ''}
            </p>
            {food.description && <p>{food.description}</p>}

            {food.nutrition && (
              <section className="panel">
                <h2>Nutrition ({food.nutrition.basis.replace(/_/g, ' ')})</h2>
                <ul>
                  <li>{food.nutrition.calories} kcal</li>
                  <li>Protein: {food.nutrition.protein_grams} g</li>
                  <li>Carbs: {food.nutrition.carbohydrate_grams} g</li>
                  <li>Fat: {food.nutrition.fat_grams} g</li>
                </ul>
              </section>
            )}

            <h2>Recipes</h2>
            {recipes.length === 0 ? (
              <EmptyState
                title="No recipes for this food yet"
                description="Nobody has published a recipe for it — yours could be the first."
              />
            ) : (
              <ul className="list">
                {recipes.map((r) => (
                  <li key={r.id}>
                    <Link to={`/recipes/${r.slug}`}>{r.title}</Link>{' '}
                    <span className="muted">
                      · {r.difficulty} · {r.total_time_minutes} min · ★ {r.rating_average}
                    </span>
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
