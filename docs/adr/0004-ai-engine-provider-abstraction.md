# ADR-0004: AI Engine — provider abstraction, gateway pipeline, versioned prompts

- **Status:** Accepted
- **Date:** 2026-07-26
- **Deciders:** Engineering, Product

## Context

Milestone 4 adds ten AI features (recipe generation, improvement, translation,
substitution, cooking assistant, meal suggestions, leftover recipes,
summarization, cooking tips, food descriptions). The features share the same
concerns — talking to an LLM, managing prompts, handling failures, controlling
cost and abuse — and must not be locked to one vendor. The build also has to be
fully testable offline (the CI sandbox cannot reach LLM APIs or install extra
Composer packages).

## Decision

- **A separate `Ai` bounded context** owns the engine; other contexts are
  referenced by ID (soft references), never by DB joins.
- **One `AiProvider` port, many adapters** (Anthropic, OpenAI, Gemini, local,
  mock). The application layer depends only on the port, so adding or swapping a
  provider never touches the gateway or feature services (Open/Closed +
  Dependency Inversion). Adapters use Laravel's HTTP client — **no extra Composer
  dependency** — against each vendor's documented API shape.
- **A single `AiGateway::generate()` pipeline** runs every feature:
  rate-limit → cache → provider (retry within a provider, then fall back to the
  next configured provider) → cost + usage logging → domain event. Features stay
  a few declarative lines; the cross-cutting behaviour lives in one place.
- **A deterministic `MockProvider`** is the default in `testing` and returns JSON
  when a prompt asks for it and prose otherwise. This makes the whole engine
  runnable in unit/feature tests with no credentials and no network — the linchpin
  of testability given the sandbox constraints.
- **Database-backed, versioned prompts.** `PromptTemplate` is an aggregate with
  one active version per feature, `{{ variable }}` interpolation, an admin API to
  publish/activate versions, and a prompt testing framework. Prompts are treated
  as source code (reviewable, versioned, rollback-able), not string literals.
- **Cost & usage as first-class data.** Every call (live or cached, success or
  failure) writes an `AiUsageLog` row; a config-driven pricing table attributes a
  USD cost. This powers quotas, the user "AI settings" screen, and billing.

## Consequences

- **Positive:** vendor-independent; resilient (retry + fallback); cheap and fast
  where possible (response cache); observable and cost-aware; fully testable
  offline; prompts iterate safely without code deploys.
- **Negative / trade-offs:** a neutral request/response shape means provider-only
  features (e.g. Anthropic extended thinking, server tools) aren't exposed yet;
  the mock's canned output only approximates real model quality, so prompt tests
  verify structure, not answer quality. Cached rewrites re-log a (zero-cost) usage
  row by design, so "requests" counts cache hits too.
- **Follow-ups:** streaming responses; per-feature model routing via the prompt's
  optional `model` pin; moving usage aggregation to a read model if volume grows;
  optional persistence of generated recipes straight into the Catalog.

## Alternatives considered

- **Call one vendor SDK directly from each feature** — fastest to write, but locks
  us in, scatters retry/caching/cost logic, and needs a Composer install the
  sandbox can't do. Rejected.
- **Hard-code prompts in PHP** — simplest, but every wording change is a code
  deploy with no history or A/B path. Rejected in favour of versioned DB prompts.
- **A managed agent platform** — more than these mostly single-shot features need,
  and it would still require the same provider/cost abstractions underneath.
