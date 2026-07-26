import { useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { FormField } from '@shared/components/FormField';
import { ApiRequestError } from '@lib/apiClient';
import { aiApi } from '../aiApi';
import type { GeneratedRecipe, GeneratedResult } from '../types';
import { AiMetaBadges } from '../components/AiMetaBadges';

/** AI Recipe Generator: describe a dish and let the model draft a recipe. */
export function AiRecipeGeneratorPage(): React.JSX.Element {
  const [dishName, setDishName] = useState('');
  const [servings, setServings] = useState(4);
  const [difficulty, setDifficulty] = useState('');
  const [dietary, setDietary] = useState('');
  const [ingredients, setIngredients] = useState('');
  const [result, setResult] = useState<GeneratedResult<GeneratedRecipe> | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  function toList(value: string): string[] {
    return value
      .split(',')
      .map((v) => v.trim())
      .filter((v) => v !== '');
  }

  async function onSubmit(event: React.FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setError(null);
    setResult(null);
    try {
      const generated = await aiApi.generateRecipe({
        dish_name: dishName,
        servings,
        difficulty: difficulty || undefined,
        dietary_preferences: toList(dietary),
        available_ingredients: toList(ingredients),
      });
      setResult(generated);
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.error.message : 'Generation failed.');
    } finally {
      setBusy(false);
    }
  }

  const recipe = result?.content;

  return (
    <Layout>
      <h1>AI Recipe Generator</h1>
      <p>Describe a dish and our AI chef will draft an authentic Nigerian recipe.</p>

      <form onSubmit={(e) => void onSubmit(e)} className="form">
        <FormField
          label="Dish name"
          name="dish_name"
          value={dishName}
          onChange={(e) => setDishName(e.target.value)}
          required
        />
        <FormField
          label="Servings"
          name="servings"
          type="number"
          min={1}
          max={50}
          value={servings}
          onChange={(e) => setServings(Number(e.target.value))}
        />
        <label className="field">
          <span className="field__label">Difficulty</span>
          <select
            className="field__input"
            value={difficulty}
            onChange={(e) => setDifficulty(e.target.value)}
          >
            <option value="">Any</option>
            <option value="easy">Easy</option>
            <option value="medium">Medium</option>
            <option value="hard">Hard</option>
          </select>
        </label>
        <FormField
          label="Dietary preferences (comma separated)"
          name="dietary"
          value={dietary}
          onChange={(e) => setDietary(e.target.value)}
          placeholder="halal, low-oil"
        />
        <FormField
          label="Ingredients you have (comma separated)"
          name="ingredients"
          value={ingredients}
          onChange={(e) => setIngredients(e.target.value)}
          placeholder="rice, tomatoes, pepper"
        />
        <Button type="submit" busy={busy}>
          Generate recipe
        </Button>
      </form>

      {error ? <p className="error">{error}</p> : null}

      {recipe ? (
        <article className="ai-result">
          <h2>{recipe.title ?? 'Your recipe'}</h2>
          {recipe.summary ? <p>{recipe.summary}</p> : null}

          {recipe.ingredients && recipe.ingredients.length > 0 ? (
            <>
              <h3>Ingredients</h3>
              <ul className="list">
                {recipe.ingredients.map((item, i) => (
                  <li key={i}>{item}</li>
                ))}
              </ul>
            </>
          ) : null}

          {recipe.steps && recipe.steps.length > 0 ? (
            <>
              <h3>Steps</h3>
              <ol className="list">
                {recipe.steps.map((step, i) => (
                  <li key={i}>{step}</li>
                ))}
              </ol>
            </>
          ) : null}

          {recipe.tips && recipe.tips.length > 0 ? (
            <>
              <h3>Tips</h3>
              <ul className="list">
                {recipe.tips.map((tip, i) => (
                  <li key={i}>{tip}</li>
                ))}
              </ul>
            </>
          ) : null}

          {result ? <AiMetaBadges meta={result.meta} /> : null}
        </article>
      ) : null}
    </Layout>
  );
}
