import { useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { ApiRequestError } from '@lib/apiClient';
import { nutritionApi } from '../nutritionApi';
import type { MealPlan, NutritionAdvice } from '../types';

/** Meal planner: list plans, create a quick plan, and see AI meal ideas. */
export function MealPlannerPage(): React.JSX.Element {
  const [plans, setPlans] = useState<MealPlan[]>([]);
  const [title, setTitle] = useState('My week');
  const [advice, setAdvice] = useState<NutritionAdvice | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  function refresh(): void {
    nutritionApi
      .mealPlans()
      .then((page) => setPlans(page.data))
      .catch(() => setPlans([]));
  }

  useEffect(refresh, []);

  async function createPlan(event: React.FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await nutritionApi.createPlan({
        title,
        period: 'weekly',
        start_date: new Date().toISOString().slice(0, 10),
        entries: [],
      });
      refresh();
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.error.message : 'Could not create plan.');
    } finally {
      setBusy(false);
    }
  }

  function loadIdeas(): void {
    nutritionApi
      .mealRecommendations()
      .then(setAdvice)
      .catch(() => setError('Set up your profile to get meal ideas.'));
  }

  return (
    <Layout>
      <h1>Meal planner</h1>

      <form onSubmit={(e) => void createPlan(e)} className="chat__form">
        <input
          className="field__input"
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          aria-label="Plan title"
        />
        <Button type="submit" busy={busy}>
          New weekly plan
        </Button>
      </form>

      {error ? <p className="error">{error}</p> : null}

      {plans.length === 0 ? (
        <p className="muted">No meal plans yet.</p>
      ) : (
        <ul className="list">
          {plans.map((p) => (
            <li key={p.id}>
              <strong>{p.title}</strong> — {p.period}, {p.entries.length} meals
              {p.estimated_cost > 0 ? ` · ~₦${p.estimated_cost.toFixed(0)}` : ''}
            </li>
          ))}
        </ul>
      )}

      <section className="ai-result">
        <h2>AI meal ideas</h2>
        <Button type="button" onClick={loadIdeas}>
          Suggest a day of meals
        </Button>
        {advice ? <p style={{ whiteSpace: 'pre-wrap' }}>{advice.advice}</p> : null}
      </section>
    </Layout>
  );
}
