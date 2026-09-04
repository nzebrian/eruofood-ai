import { Link } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { AsyncView, EmptyState, ErrorState, Loading } from '@shared/components/StateViews';
import { useAsyncData } from '@shared/hooks/useAsyncData';
import { ApiRequestError } from '@lib/apiClient';
import { nutritionApi } from '../nutritionApi';

/**
 * Codes the API uses to say "you have not filled in your health profile".
 * Everything else is a fault, and must not be reported as one of these.
 */
const PROFILE_MISSING_CODES = ['NUTRITION_PROFILE_INCOMPLETE', 'NUTRITION_RESOURCE_NOT_FOUND'];

function isProfileMissing(error: unknown): boolean {
  return error instanceof ApiRequestError && PROFILE_MISSING_CODES.includes(error.error.code);
}

/** Nutrition dashboard: today's targets, intake so far, and remaining calories. */
export function NutritionDashboardPage(): React.JSX.Element {
  const today = new Date().toISOString().slice(0, 10);

  // `.catch(() => setNeedsProfile(true))` told every visitor to set up their
  // health profile whenever anything went wrong — including when the profile
  // was already complete and the server had simply fallen over. The code the
  // API actually sends is the discriminator, so it is what is checked.
  const assessment = useAsyncData(async () => {
    try {
      return { kind: 'ready' as const, value: await nutritionApi.assessment() };
    } catch (error) {
      if (isProfileMissing(error)) return { kind: 'no-profile' as const };
      throw error;
    }
  }, 'nutrition|assessment');

  const diary = useAsyncData(() => nutritionApi.diaryDay(today), `nutrition|diary|${today}`);

  return (
    <Layout>
      <h1>Nutrition dashboard</h1>

      <AsyncView
        state={assessment.state}
        loadingLabel="Loading your targets…"
        errorTitle="We could not load your targets"
        onRetry={assessment.reload}
      >
        {(result) =>
          result.kind === 'no-profile' ? (
            <EmptyState
              title="Tell us about yourself first"
              description="Your calorie and macro targets are calculated from your health profile."
              action={
                <Link className="button button--secondary" to="/nutrition/profile">
                  Set up your health profile
                </Link>
              }
            />
          ) : (
            <dl className="usage">
              <div>
                <dt>BMI</dt>
                <dd>
                  {result.value.bmi} <small>({result.value.bmi_category})</small>
                </dd>
              </div>
              <div>
                <dt>Calorie target</dt>
                <dd>{result.value.calorie_target}</dd>
              </div>
              <div>
                <dt>BMR / TDEE</dt>
                <dd>
                  {result.value.bmr} / {result.value.tdee}
                </dd>
              </div>
              <div>
                <dt>Macros (P / C / F)</dt>
                <dd>
                  {result.value.macro_targets.protein_grams} /{' '}
                  {result.value.macro_targets.carb_grams} / {result.value.macro_targets.fat_grams} g
                </dd>
              </div>
            </dl>
          )
        }
      </AsyncView>

      <section className="ai-result">
        <h2>Today</h2>
        {/* The diary used to `.catch(() => undefined)`, so a failure removed
            this whole section from the page with no trace. */}
        {diary.state.status === 'loading' ? (
          <Loading label="Loading today's diary…" />
        ) : diary.state.status === 'error' ? (
          <ErrorState
            title="We could not load today's diary"
            message={diary.state.message}
            onRetry={diary.reload}
          />
        ) : (
          <>
            <p>
              {diary.state.data.date} — eaten:{' '}
              <strong>{Math.round(diary.state.data.totals.calories)}</strong> kcal
              {diary.state.data.remaining_calories !== null ? (
                <>
                  {' '}
                  · Remaining: <strong>{diary.state.data.remaining_calories}</strong> kcal
                </>
              ) : null}
            </p>
            {diary.state.data.entries.length === 0 ? (
              <EmptyState
                title="Nothing logged yet today"
                description="Log a meal and it will count towards your targets."
              />
            ) : (
              <ul className="list">
                {diary.state.data.entries.map((e) => (
                  <li key={e.id}>
                    {e.meal_type}: {e.item_name} ({Math.round(e.nutrition.calories)} kcal)
                  </li>
                ))}
              </ul>
            )}
          </>
        )}
      </section>
    </Layout>
  );
}
