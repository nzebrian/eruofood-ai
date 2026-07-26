<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Application\Service;

use EruoFood\Nutrition\Application\DTO\NutritionAdvice;
use EruoFood\Nutrition\Application\Port\NutritionAdvisor;
use EruoFood\Nutrition\Domain\Health\HealthProfile;
use EruoFood\Nutrition\Domain\ValueObject\NutritionAssessment;

/**
 * AI personalisation: turns a user's profile, targets and recent tracking into
 * concrete advice via the {@see NutritionAdvisor} port (which bridges to the AI
 * Engine). Each method assembles a focused prompt; the AI module handles model
 * selection, caching, cost and usage logging.
 *
 * Nigerian-food context is baked into the system persona so recommendations stay
 * culturally relevant.
 */
final readonly class RecommendationService
{
    private const PERSONA = 'You are a registered dietitian for EruoFood AI who specialises in Nigerian cuisine. '
        .'Give practical, culturally-relevant, budget-aware advice using real Nigerian foods. '
        .'Be concise and encouraging.';

    public function __construct(
        private NutritionAdvisor $advisor,
        private HealthProfileService $profiles,
        private NutritionCalculatorService $calculator,
        private DiaryService $diary,
        private ProgressService $progress,
    ) {
    }

    /** Personalised meal recommendations for the day. */
    public function personalisedMeals(string $userId): NutritionAdvice
    {
        $profile = $this->profiles->getOrFail($userId);
        $assessment = $this->calculator->assessForUser($userId);

        $prompt = $this->profileContext($profile, $assessment)
            ."\n\nSuggest a full day of Nigerian meals (breakfast, lunch, dinner, one snack) "
            .'that fits these calorie and macro targets. For each, give the dish and a one-line reason.';

        return $this->advisor->advise(self::PERSONA, $prompt, $userId);
    }

    /** Smart nutrition suggestions based on what the user has eaten today. */
    public function nutritionSuggestions(string $userId, string $date): NutritionAdvice
    {
        $profile = $this->profiles->getOrFail($userId);
        $assessment = $this->calculator->assessForUser($userId);
        $summary = $this->diary->day($userId, $date);
        $totals = $summary->totals;

        $prompt = $this->profileContext($profile, $assessment)
            .sprintf(
                "\n\nSo far today the user has eaten about %d kcal (protein %dg, carbs %dg, fat %dg). ",
                (int) round($totals->calories),
                (int) round($totals->proteinGrams),
                (int) round($totals->carbGrams),
                (int) round($totals->fatGrams),
            )
            .'Suggest what to eat for the rest of the day to hit their targets without exceeding them.';

        return $this->advisor->advise(self::PERSONA, $prompt, $userId);
    }

    /** Diet improvement recommendations. */
    public function dietImprovement(string $userId): NutritionAdvice
    {
        $profile = $this->profiles->getOrFail($userId);
        $assessment = $this->calculator->assessForUser($userId);

        $prompt = $this->profileContext($profile, $assessment)
            ."\n\nGive five specific, achievable ways this user can improve their diet toward their goal, "
            .'favouring healthier Nigerian food choices and portion habits.';

        return $this->advisor->advise(self::PERSONA, $prompt, $userId);
    }

    /** Weekly nutrition & progress insights. */
    public function weeklyInsights(string $userId): NutritionAdvice
    {
        $profile = $this->profiles->getOrFail($userId);
        $assessment = $this->calculator->assessForUser($userId);
        $history = $this->progress->history($userId, 14);

        $weights = implode(', ', array_map(
            static fn ($e): string => $e->date().': '.$e->weightKg().'kg',
            $history,
        ));

        $prompt = $this->profileContext($profile, $assessment)
            ."\n\nRecent weight log: ".($weights !== '' ? $weights : 'no measurements yet').". "
            .'Summarise their progress toward their goal in 3-4 sentences and give one focus for next week.';

        return $this->advisor->advise(self::PERSONA, $prompt, $userId);
    }

    private function profileContext(HealthProfile $profile, NutritionAssessment $assessment): string
    {
        return sprintf(
            'User: %d-year-old %s, %.0fkg, %.0fcm, %s activity, goal "%s". '
            .'BMI %.1f (%s). Daily targets: %d kcal, protein %dg, carbs %dg, fat %dg. '
            .'Dietary preferences: %s. Allergies: %s. Medical restrictions: %s.',
            $profile->age(),
            $profile->gender()->value,
            $profile->weightKg(),
            $profile->heightCm(),
            $profile->activityLevel()->value,
            $profile->goal()->value,
            $assessment->bmi,
            $assessment->bmiCategory,
            $assessment->calorieTarget,
            $assessment->macroTargets->proteinGrams,
            $assessment->macroTargets->carbGrams,
            $assessment->macroTargets->fatGrams,
            $this->listOrNone($profile->dietaryPreferences()),
            $this->listOrNone($profile->allergies()),
            $this->listOrNone($profile->medicalRestrictions()),
        );
    }

    /** @param list<string> $items */
    private function listOrNone(array $items): string
    {
        return $items === [] ? 'none' : implode(', ', $items);
    }
}
