import { useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { FormField } from '@shared/components/FormField';
import { ApiRequestError } from '@lib/apiClient';
import { nutritionApi, type HealthProfilePayload } from '../nutritionApi';

const GOALS = ['lose_weight', 'maintain', 'gain_weight', 'gain_muscle'];
const ACTIVITY = ['sedentary', 'light', 'moderate', 'active', 'very_active'];

/** Health profile: capture the inputs the calculators and personalisation need. */
export function HealthProfilePage(): React.JSX.Element {
  const [form, setForm] = useState<HealthProfilePayload>({
    weight_kg: 80,
    height_cm: 175,
    age: 30,
    gender: 'male',
    activity_level: 'moderate',
    goal: 'maintain',
  });
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    nutritionApi
      .getProfile()
      .then((p) => {
        if (p) {
          setForm({
            weight_kg: p.weight_kg,
            height_cm: p.height_cm,
            age: p.age,
            gender: p.gender,
            activity_level: p.activity_level,
            goal: p.goal,
            dietary_preferences: p.dietary_preferences,
            allergies: p.allergies,
            medical_restrictions: p.medical_restrictions,
          });
        }
      })
      .catch(() => undefined);
  }, []);

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
      setError(err instanceof ApiRequestError ? err.error.message : 'Could not save profile.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <Layout>
      <h1>Health profile</h1>
      <p>Your details drive the calorie, macro and personalisation features.</p>

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
          <select className="field__input" value={form.goal} onChange={(e) => set('goal', e.target.value)}>
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

      {saved ? <p className="success">Profile saved.</p> : null}
      {error ? <p className="error">{error}</p> : null}
    </Layout>
  );
}
