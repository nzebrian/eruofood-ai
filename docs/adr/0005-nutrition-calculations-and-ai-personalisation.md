# ADR-0005: Nutrition — owned nutrition data, pure calculators, AI via a contract

- **Status:** Accepted
- **Date:** 2026-07-26
- **Deciders:** Engineering, Product

## Context

Milestone 5 adds nutrition, health profiles, calculators (BMI/BMR/TDEE/targets),
daily tracking, meal planning, progress tracking and AI personalisation. Two
forces shape the design: nutrition analysis needs food-level nutrition data that
the Catalog owns only sparsely, and personalisation needs the AI Engine — but the
Modular Monolith rules forbid cross-context table joins and depending on another
module's internals.

## Decision

- **A separate `Nutrition` bounded context** owns its own **nutrition database**
  (`NutritionItem` with a full `NutritionFacts` panel). Meal analysis, diary
  totals and recipe breakdowns are computed by scaling and summing these panels.
  Other contexts are referenced by ID only (soft `user_id` → Identity, optional
  `food_id` → Catalog); there are no cross-context joins.
- **Calculations live in a pure domain service** (`NutritionCalculator`) using
  the documented Mifflin-St Jeor / Harris-Benedict formulae, parameterised by a
  `CalculatorSettings` value object built from `config/nutrition.php`. The domain
  stays framework-free while the physiological constants remain configurable and
  reviewable in one place.
- **Diary entries snapshot the nutrition consumed** at log time, so editing an
  item never rewrites a past day's totals.
- **AI personalisation goes through a published contract.** Nutrition depends on
  its own `NutritionAdvisor` port; the adapter calls the AI module's new public
  `EruoFood\Ai\Contracts\AiAdvisor` contract — never AI internals. The AI Engine
  runs the request through its full pipeline (provider selection, fallback,
  caching, rate-limiting, cost + usage logging), attributing it to a neutral
  `external_advice` feature so the AI enum isn't polluted with nutrition concerns.

## Consequences

- **Positive:** analysis is self-contained and fast (no cross-context reads);
  the maths is auditable and unit-tested against known values; personalisation
  reuses all the AI Engine's resilience and observability; because the AI module
  mocks its provider in tests, the entire nutrition + personalisation path runs
  offline in CI.
- **Negative / trade-offs:** the nutrition database duplicates some foods that
  also exist in the Catalog (linked only by an optional `food_id`); keeping them
  in sync is a future concern. The `AiAdvisor` contract is intentionally minimal
  (system + prompt in, text out), so structured AI output for nutrition would
  need the caller to parse it.
- **Follow-ups:** a reconciliation job to link/seed nutrition items from Catalog
  foods; richer micronutrient RDAs and adequacy scoring; caching assessments;
  moving diary/day aggregation to a read model if volume grows.

## Alternatives considered

- **Read nutrition from the Catalog at analysis time** — avoids duplication but
  requires cross-context reads/joins and couples the two contexts' schemas.
  Rejected in favour of an owned nutrition database referenced by soft ID.
- **Call the AI SDK directly from Nutrition** — would duplicate the AI Engine's
  provider/cost/caching logic and breach the "Contracts only" rule. Rejected in
  favour of the published `AiAdvisor` contract.
- **Hard-code the physiological constants in the domain** — simplest, but makes
  tuning a code change. Rejected in favour of config-driven `CalculatorSettings`.
