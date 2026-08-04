import { useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { FormField } from '@shared/components/FormField';
import { ApiRequestError } from '@lib/apiClient';
import { nutritionApi } from '../nutritionApi';
import type { NutritionAdvice, ProgressEntry } from '../types';

/** Progress dashboard: log weight, view history, and read weekly AI insights. */
export function ProgressDashboardPage(): React.JSX.Element {
  const [history, setHistory] = useState<ProgressEntry[]>([]);
  const [weight, setWeight] = useState(80);
  const [insight, setInsight] = useState<NutritionAdvice | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  function refresh(): void {
    nutritionApi
      .progress()
      .then(setHistory)
      .catch(() => setHistory([]));
  }

  useEffect(refresh, []);

  async function record(event: React.FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await nutritionApi.recordProgress({
        date: new Date().toISOString().slice(0, 10),
        weight_kg: weight,
      });
      refresh();
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.error.message : 'Could not record weight.');
    } finally {
      setBusy(false);
    }
  }

  function loadInsights(): void {
    nutritionApi
      .weeklyInsights()
      .then(setInsight)
      .catch(() => setError('Set up your profile to get insights.'));
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

      {error ? <p className="error">{error}</p> : null}

      {history.length === 0 ? (
        <p className="muted">No measurements yet.</p>
      ) : (
        <ul className="list">
          {history.map((e) => (
            <li key={e.id}>
              {e.date}: <strong>{e.weight_kg} kg</strong>
              {e.note ? ` — ${e.note}` : ''}
            </li>
          ))}
        </ul>
      )}

      <section className="ai-result">
        <h2>Weekly insights</h2>
        <Button type="button" onClick={loadInsights}>
          Get AI insights
        </Button>
        {insight ? <p style={{ whiteSpace: 'pre-wrap' }}>{insight.advice}</p> : null}
      </section>
    </Layout>
  );
}
