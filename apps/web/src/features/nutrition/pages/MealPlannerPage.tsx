import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { AsyncView, EmptyState } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { ApiRequestError } from '@lib/apiClient';
import { nutritionApi } from '../nutritionApi';
import type { NutritionAdvice } from '../types';

/** Meal planner: list plans, create a quick plan, and see AI meal ideas. */
export function MealPlannerPage(): React.JSX.Element {
  const [title, setTitle] = useState('My week');
  const [advice, setAdvice] = useState<NutritionAdvice | null>(null);
  const [adviceState, setAdviceState] = useState<'idle' | 'loading' | 'no-profile' | 'error'>(
    'idle',
  );
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const plans = useAsyncData(() => nutritionApi.mealPlans(), 'nutrition|plans');

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
      plans.reload();
    } catch (err) {
      setError(describeError(err, 'Could not create plan.'));
    } finally {
      setBusy(false);
    }
  }

  function loadIdeas(): void {
    setAdviceState('loading');
    setError(null);
    nutritionApi
      .mealRecommendations()
      .then((result) => {
        setAdvice(result);
        setAdviceState('idle');
      })
      .catch((err: unknown) => {
        // "Set up your profile" was the answer to every failure here. It is
        // now the answer only when the API says the profile is the problem.
        if (err instanceof ApiRequestError && err.error.code === 'NUTRITION_PROFILE_INCOMPLETE') {
          setAdviceState('no-profile');
          return;
        }
        setAdviceState('error');
        setError(describeError(err, 'Could not get meal ideas.'));
      });
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

      {error !== null ? (
        <p className="error" role="alert">
          {error}
        </p>
      ) : null}

      <AsyncView
        state={plans.state}
        loadingLabel="Loading your meal plans…"
        errorTitle="We could not load your meal plans"
        onRetry={plans.reload}
      >
        {(page) =>
          page.data.length === 0 ? (
            <EmptyState
              title="No meal plans yet"
              description="Create a weekly plan with the form above."
            />
          ) : (
            <ul className="list">
              {page.data.map((p) => (
                <li key={p.id}>
                  <strong>{p.title}</strong> — {p.period}, {p.entries.length} meals
                  {p.estimated_cost > 0 ? ` · ~₦${p.estimated_cost.toFixed(0)}` : ''}
                </li>
              ))}
            </ul>
          )
        }
      </AsyncView>

      <section className="ai-result">
        <h2>AI meal ideas</h2>
        <Button type="button" busy={adviceState === 'loading'} onClick={loadIdeas}>
          Suggest a day of meals
        </Button>
        {adviceState === 'no-profile' ? (
          <EmptyState
            title="We need your health profile first"
            description="Meal ideas are personalised from your targets."
            action={
              <Link className="button button--secondary" to="/nutrition/profile">
                Set up your health profile
              </Link>
            }
          />
        ) : advice ? (
          <p className="prewrap">{advice.advice}</p>
        ) : null}
      </section>
    </Layout>
  );
}
