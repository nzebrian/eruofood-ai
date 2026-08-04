import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { nutritionApi } from '../nutritionApi';
import type { Assessment, DailySummary } from '../types';

/** Nutrition dashboard: today's targets, intake so far, and remaining calories. */
export function NutritionDashboardPage(): React.JSX.Element {
  const [assessment, setAssessment] = useState<Assessment | null>(null);
  const [summary, setSummary] = useState<DailySummary | null>(null);
  const [needsProfile, setNeedsProfile] = useState(false);
  const today = new Date().toISOString().slice(0, 10);

  useEffect(() => {
    nutritionApi
      .assessment()
      .then(setAssessment)
      .catch(() => setNeedsProfile(true));
    nutritionApi
      .diaryDay(today)
      .then(setSummary)
      .catch(() => undefined);
  }, [today]);

  if (needsProfile) {
    return (
      <Layout>
        <h1>Nutrition dashboard</h1>
        <p>
          Set up your <Link to="/nutrition/profile">health profile</Link> to see your calorie and macro
          targets.
        </p>
      </Layout>
    );
  }

  return (
    <Layout>
      <h1>Nutrition dashboard</h1>

      {assessment ? (
        <div className="usage">
          <div>
            <dt>BMI</dt>
            <dd>
              {assessment.bmi} <small>({assessment.bmi_category})</small>
            </dd>
          </div>
          <div>
            <dt>Calorie target</dt>
            <dd>{assessment.calorie_target}</dd>
          </div>
          <div>
            <dt>BMR / TDEE</dt>
            <dd>
              {assessment.bmr} / {assessment.tdee}
            </dd>
          </div>
          <div>
            <dt>Macros (P / C / F)</dt>
            <dd>
              {assessment.macro_targets.protein_grams} / {assessment.macro_targets.carb_grams} /{' '}
              {assessment.macro_targets.fat_grams} g
            </dd>
          </div>
        </div>
      ) : (
        <p>Loading…</p>
      )}

      {summary ? (
        <section className="ai-result">
          <h2>Today ({summary.date})</h2>
          <p>
            Eaten: <strong>{Math.round(summary.totals.calories)}</strong> kcal
            {summary.remaining_calories !== null ? (
              <>
                {' '}
                · Remaining: <strong>{summary.remaining_calories}</strong> kcal
              </>
            ) : null}
          </p>
          {summary.entries.length === 0 ? (
            <p className="muted">Nothing logged yet today.</p>
          ) : (
            <ul className="list">
              {summary.entries.map((e) => (
                <li key={e.id}>
                  {e.meal_type}: {e.item_name} ({Math.round(e.nutrition.calories)} kcal)
                </li>
              ))}
            </ul>
          )}
        </section>
      ) : null}
    </Layout>
  );
}
