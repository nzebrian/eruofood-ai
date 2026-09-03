import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { FormField } from '@shared/components/FormField';
import { AsyncView, EmptyState } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { ApiRequestError } from '@lib/apiClient';
import { nutritionApi } from '../nutritionApi';
import type { NutritionAdvice } from '../types';

/** Progress dashboard: log weight, view history, and read weekly AI insights. */
export function ProgressDashboardPage(): React.JSX.Element {
  const [weight, setWeight] = useState(80);
  const [insight, setInsight] = useState<NutritionAdvice | null>(null);
  const [insightState, setInsightState] = useState<'idle' | 'loading' | 'no-profile'>('idle');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const history = useAsyncData(() => nutritionApi.progress(), 'nutrition|progress');

  async function record(event: React.FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await nutritionApi.recordProgress({
        date: new Date().toISOString().slice(0, 10),
        weight_kg: weight,
      });
      history.reload();
    } catch (err) {
      setError(describeError(err, 'Could not record weight.'));
    } finally {
      setBusy(false);
    }
  }

  function loadInsights(): void {
    setInsightState('loading');
    setError(null);
    nutritionApi
      .weeklyInsights()
      .then((result) => {
        setInsight(result);
        setInsightState('idle');
      })
      .catch((err: unknown) => {
        if (err instanceof ApiRequestError && err.error.code === 'NUTRITION_PROFILE_INCOMPLETE') {
          setInsightState('no-profile');
          return;
        }
        setInsightState('idle');
        setError(describeError(err, 'Could not get your insights.'));
      });
  }

  return (
    <Layout>
      <h1>Progress</h1>

      <form onSubmit={(e) => void record(e)} className="form">
        <FormField
          label="Today's weight (kg)"
          name="weight"
          type="number"
          value={weight}
          onChange={(e) => setWeight(Number(e.target.value))}
        />
        <Button type="submit" busy={busy}>
          Record weight
        </Button>
      </form>

      {error !== null ? (
        <p className="error" role="alert">
          {error}
        </p>
      ) : null}

      <AsyncView
        state={history.state}
        loadingLabel="Loading your measurements…"
        errorTitle="We could not load your measurements"
        onRetry={history.reload}
      >
        {(entries) =>
          entries.length === 0 ? (
            <EmptyState
              title="No measurements yet"
              description="Record today's weight above to start tracking."
            />
          ) : (
            <ul className="list">
              {entries.map((e) => (
                <li key={e.id}>
                  {e.date}: <strong>{e.weight_kg} kg</strong>
                  {e.note ? ` — ${e.note}` : ''}
                </li>
              ))}
            </ul>
          )
        }
      </AsyncView>

      <section className="ai-result">
        <h2>Weekly insights</h2>
        <Button type="button" busy={insightState === 'loading'} onClick={loadInsights}>
          Get AI insights
        </Button>
        {insightState === 'no-profile' ? (
          <EmptyState
            title="We need your health profile first"
            description="Insights compare your measurements against your targets."
            action={
              <Link className="button button--secondary" to="/nutrition/profile">
                Set up your health profile
              </Link>
            }
          />
        ) : insight ? (
          <p className="prewrap">{insight.advice}</p>
        ) : null}
      </section>
    </Layout>
  );
}
