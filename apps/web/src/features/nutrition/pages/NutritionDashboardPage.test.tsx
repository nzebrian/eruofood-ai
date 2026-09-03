import { MemoryRouter } from 'react-router-dom';
import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiRequestError } from '@lib/apiClient';
import type { Assessment, DailySummary } from '../types';
import { NutritionDashboardPage } from './NutritionDashboardPage';

/**
 * M48 / F-09, the subtler variant.
 *
 * This page used to catch *every* error into `setNeedsProfile(true)`, so a
 * 500 told a user whose health profile was already complete to go and fill it
 * in again. The API sends `NUTRITION_PROFILE_INCOMPLETE` when the profile is
 * genuinely the problem, so that code — not the mere fact of a rejection — is
 * what the page now keys on.
 */

const assessment = vi.hoisted(() => vi.fn<() => Promise<Assessment>>());
const diaryDay = vi.hoisted(() => vi.fn<(date: string) => Promise<DailySummary>>());

vi.mock('../nutritionApi', () => ({
  nutritionApi: { assessment, diaryDay },
}));

vi.mock('@features/auth/useAuth', () => ({
  useAuth: () => ({ user: null, loading: false }),
}));

const TARGETS: Assessment = {
  bmi: 23.4,
  bmi_category: 'normal',
  bmr: 1700,
  tdee: 2400,
  calorie_target: 2200,
  macro_targets: { protein_grams: 130, carb_grams: 250, fat_grams: 70 },
};

const DIARY: DailySummary = {
  date: '2026-09-03',
  entries: [],
  totals: {
    calories: 0,
    protein_grams: 0,
    carb_grams: 0,
    fat_grams: 0,
    fibre_grams: 0,
    sugar_grams: 0,
    sodium_mg: 0,
    cholesterol_mg: 0,
    water_ml: 0,
    micronutrients: {},
  },
  targets: null,
  // Deliberately different from `calorie_target` so the assertions below name
  // one number each.
  remaining_calories: 1850,
};

function renderPage(): void {
  render(
    <MemoryRouter>
      <NutritionDashboardPage />
    </MemoryRouter>,
  );
}

beforeEach(() => {
  assessment.mockReset().mockResolvedValue(TARGETS);
  diaryDay.mockReset().mockResolvedValue(DIARY);
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe('NutritionDashboardPage', () => {
  it('renders the targets once they load', async () => {
    renderPage();

    expect(await screen.findByText('23.4')).toBeInTheDocument();
    expect(screen.getByText('2200')).toBeInTheDocument();
  });

  it('asks for a health profile only when the API says the profile is missing', async () => {
    assessment.mockRejectedValue(
      new ApiRequestError(422, {
        code: 'NUTRITION_PROFILE_INCOMPLETE',
        message: 'Health profile required.',
      }),
    );
    renderPage();

    expect(await screen.findByText('Tell us about yourself first')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /set up your health profile/i })).toHaveAttribute(
      'href',
      '/nutrition/profile',
    );
  });

  it('does not blame the user for a server fault', async () => {
    assessment.mockRejectedValue(
      new ApiRequestError(500, { code: 'SERVER_ERROR', message: 'Calculator unavailable.' }),
    );
    renderPage();

    const alerts = await screen.findAllByRole('alert');
    const targetsAlert = alerts.find((a) =>
      a.textContent?.includes('We could not load your targets'),
    );
    expect(targetsAlert).toBeDefined();
    expect(targetsAlert).toHaveTextContent('Calculator unavailable.');
    // The old behaviour: telling somebody with a complete profile to set one up.
    expect(screen.queryByText('Tell us about yourself first')).not.toBeInTheDocument();
  });

  it("keeps today's diary on screen as an error rather than removing the section", async () => {
    diaryDay.mockRejectedValue(
      new ApiRequestError(500, { code: 'SERVER_ERROR', message: 'Diary unavailable.' }),
    );
    renderPage();

    // `.catch(() => undefined)` used to delete this whole section silently.
    expect(await screen.findByText("We could not load today's diary")).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Today' })).toBeInTheDocument();
  });

  it('distinguishes an empty diary from a failed one', async () => {
    renderPage();

    expect(await screen.findByText('Nothing logged yet today')).toBeInTheDocument();
    expect(screen.queryByText("We could not load today's diary")).not.toBeInTheDocument();
  });
});
