<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Infrastructure\Provider;

use EruoFood\Nutrition\Application\Port\NutritionAdvisor;
use EruoFood\Nutrition\Domain\Diary\DiaryRepository;
use EruoFood\Nutrition\Domain\Health\HealthProfileRepository;
use EruoFood\Nutrition\Domain\Item\NutritionItemRepository;
use EruoFood\Nutrition\Domain\Plan\MealPlanRepository;
use EruoFood\Nutrition\Domain\Progress\ProgressRepository;
use EruoFood\Nutrition\Domain\Service\CalculatorSettings;
use EruoFood\Nutrition\Infrastructure\Advisor\AiNutritionAdvisor;
use EruoFood\Nutrition\Infrastructure\Persistence\Eloquent\EloquentDiaryRepository;
use EruoFood\Nutrition\Infrastructure\Persistence\Eloquent\EloquentHealthProfileRepository;
use EruoFood\Nutrition\Infrastructure\Persistence\Eloquent\EloquentMealPlanRepository;
use EruoFood\Nutrition\Infrastructure\Persistence\Eloquent\EloquentNutritionItemRepository;
use EruoFood\Nutrition\Infrastructure\Persistence\Eloquent\EloquentProgressRepository;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root for the Nutrition, Health & Personalisation module.
 *
 * Binds the repository ports to their Eloquent adapters, wires the AI
 * personalisation port to the adapter that bridges to the AI module's public
 * contract, and builds the {@see CalculatorSettings} from config so the pure
 * domain calculator stays framework-free yet configurable.
 */
final class NutritionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(HealthProfileRepository::class, EloquentHealthProfileRepository::class);
        $this->app->bind(NutritionItemRepository::class, EloquentNutritionItemRepository::class);
        $this->app->bind(DiaryRepository::class, EloquentDiaryRepository::class);
        $this->app->bind(MealPlanRepository::class, EloquentMealPlanRepository::class);
        $this->app->bind(ProgressRepository::class, EloquentProgressRepository::class);

        // AI personalisation goes through the AI module's published contract.
        $this->app->bind(NutritionAdvisor::class, AiNutritionAdvisor::class);

        $this->app->instance(CalculatorSettings::class, $this->calculatorSettings());
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../../Interface/Http/routes.php');

        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migration');
    }

    private function calculatorSettings(): CalculatorSettings
    {
        $config = $this->app->make(\Illuminate\Contracts\Config\Repository::class);

        /** @var array<string, float> $factors */
        $factors = $config->get('nutrition.activity_factors', []);
        /** @var array<string, int> $adjustments */
        $adjustments = $config->get('nutrition.goal_calorie_adjustment', []);
        /** @var array<string, array{protein: int, carbs: int, fat: int}> $splits */
        $splits = $config->get('nutrition.macro_split', []);

        $defaults = CalculatorSettings::defaults();

        return new CalculatorSettings(
            activityFactors: $factors !== [] ? $factors : $defaults->activityFactors,
            goalAdjustments: $adjustments !== [] ? $adjustments : $defaults->goalAdjustments,
            macroSplits: $splits !== [] ? $splits : $defaults->macroSplits,
            minCalories: (int) $config->get('nutrition.min_calories', 1200),
        );
    }
}
