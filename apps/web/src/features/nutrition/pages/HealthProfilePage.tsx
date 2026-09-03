import { useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { FormField } from '@shared/components/FormField';
import { AsyncView } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { nutritionApi, type HealthProfilePayload } from '../nutritionApi';

const GOALS = ['lose_weight', 'maintain', 'gain_weight', 'gain_muscle'];
const ACTIVITY = ['sedentary', 'light', 'moderate', 'active', 'very_active'];

const BLANK: HealthProfilePayload = {
  weight_kg: 80,
  height_cm: 175,
  age: 30,
  gender: 'male',
  activity_level: 'moderate',
  goal: 'maintain',
};

/** Health profile: capture the inputs the calculators and personalisation need. */
export function HealthProfilePage(): React.JSX.Element {
  // The load used to `.catch(() => undefined)` and leave the form showing its
  // placeholder defaults — 80 kg, 175 cm, 30 years — which are indistinguishable
  // from a saved profile. Somebody whose profile failed to load would have
  // saved those numbers over their real ones. The form is therefore not
  // rendered at all until the read has succeeded.
  const existing = useAsyncData(() => nutritionApi.getProfile(), 'nutrition|profile');

  return (
    <Layout>
      <h1>Health profile</h1>
      <p>Your details drive the calorie, macro and personalisation features.</p>

      <AsyncView
        state={existing.state}
        loadingLabel="Loading your profile…"
        errorTitle="We could not load your profile"
        onRetry={existing.reload}
      >
        {(profile) => (
          <ProfileForm
            // Remount the form when a reload brings different saved values,
            // so the fields reflect what the server actually holds.
            key={profile === null ? 'blank' : 'saved'}
            initial={
              profile === null
                ? BLANK
                : {
                    weight_kg: profile.weight_kg,
                    height_cm: profile.height_cm,
                    age: profile.age,
                    gender: profile.gender,
                    activity_level: profile.activity_level,
                    goal: profile.goal,
                    dietary_preferences: profile.dietary_preferences,
                    allergies: profile.allergies,
                    medical_restrictions: profile.medical_restrictions,
                  }
            }
            isNew={profile === null}
          />
        )}
      </AsyncView>
    </Layout>
  );
}

function ProfileForm({
  initial,
  isNew,
}: {
  initial: HealthProfilePayload;
  isNew: boolean;
}): React.JSX.Element {
  const [form, setForm] = useState<HealthProfilePayload>(initial);
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  function set<K extends keyof HealthProfilePayload>(key: K, value: HealthProfilePayload[K]): void {
    setForm((prev) => ({ ...prev, [key]: value }));
  }

  async function onSubmit(event: React.FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setError(null);
    setSaved(false);
    try {
      await nutritionApi.saveProfile(form);
      setSaved(true);
    } catch (err) {
      setError(describeError(err, 'Could not save profile.'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      {isNew ? (
        <p className="muted">
          You have not saved a profile yet — the values below are starting points, not your data.
        </p>
      ) : null}

      <form onSubmit={(e) => void onSubmit(e)} className="form">
        <FormField
          label="Weight (kg)"
          name="weight_kg"
          type="number"
          value={form.weight_kg}
          onChange={(e) => set('weight_kg', Number(e.target.value))}
        />
        <FormField
          label="Height (cm)"
          name="height_cm"
          type="number"
          value={form.height_cm}
          onChange={(e) => set('height_cm', Number(e.target.value))}
        />
        <FormField
          label="Age"
          name="age"
          type="number"
          value={form.age}
          onChange={(e) => set('age', Number(e.target.value))}
        />
        <label className="field">
          <span className="field__label">Gender</span>
          <select
            className="field__input"
            value={form.gender}
            onChange={(e) => set('gender', e.target.value)}
          >
            <option value="male">Male</option>
            <option value="female">Female</option>
            <option value="other">Other</option>
          </select>
        </label>
        <label className="field">
          <span className="field__label">Activity level</span>
          <select
            className="field__input"
            value={form.activity_level}
            onChange={(e) => set('activity_level', e.target.value)}
          >
            {ACTIVITY.map((a) => (
              <option key={a} value={a}>
                {a.replace('_', ' ')}
              </option>
            ))}
          </select>
        </label>
        <label className="field">
          <span className="field__label">Goal</span>
          <select
            className="field__input"
            value={form.goal}
            onChange={(e) => set('goal', e.target.value)}
          >
            {GOALS.map((g) => (
              <option key={g} value={g}>
                {g.replace('_', ' ')}
              </option>
            ))}
          </select>
        </label>
        <Button type="submit" busy={busy}>
          Save profile
        </Button>
      </form>

      {saved ? (
        <p className="success" role="status">
          Profile saved.
        </p>
      ) : null}
      {error !== null ? (
        <p className="error" role="alert">
          {error}
        </p>
      ) : null}
    </>
  );
}
