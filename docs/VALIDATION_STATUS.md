# Validation Status

Date: 2026-08-02
Scope: Technical Debt & Validation Cleanup (no new business features).

This document records **exactly what was run and what was not**, and why. Checks
are classified as:

- ✅ **Executed and Passed** — a real tool ran to completion with a passing result.
- ❌ **Executed and Failed** — a real tool ran and reported failures.
- 🟡 **Static Validation Only** — verified by inspection/scripts/linters that do
  not execute the application or its test suites.
- ⚪ **Not Validated** — could not be checked in this environment.

Environment limitations are stated plainly; nothing is claimed to have "passed"
unless it actually executed.

---

## ✅ Executed and Passed

| Check | Tool | Result |
|---|---|---|
| OpenAPI specification validity | `@redocly/cli@1.25.0 lint` | **0 errors** (363 style warnings — see TECHNICAL_DEBT.md) |
| OpenAPI generated TS client | `openapi-typescript@7.4.1` | Generated cleanly; `MessagingConversation`/`MessagingMessage` present |
| Web type-check | `tsc --noEmit` | Exit 0 |
| Web lint | `eslint src` | Exit 0 |
| Web unit tests | `vitest run` | **46 passed / 46** (14 files) |
| Web production build | `vite build` | Exit 0 |
| PHP syntax (all module files) | `php -l` | **1390/1390** clean |
| Composer manifest | `composer validate` | `composer.json is valid` |
| Domain logic — Loyalty | pure-PHP sanity harness | **52 checks passed / 52** |
| Domain logic — Reviews | pure-PHP sanity harness | **40 checks passed / 40** |

The two PHP "sanity harnesses" `require` the domain classes directly (no
framework) and exercise the trickiest algorithms — points ledger math, tier
resolution, redemption/referral lifecycles (Loyalty); rating projection,
moderation transitions, content filter (Reviews). They are a genuine, executed
substitute for the parts of the Pest suite that cannot run here (see below).

## 🟡 Static Validation Only

| Check | Method | Result |
|---|---|---|
| Cross-module reference resolution (PHP) | script resolving every `EruoFood\…` symbol against PSR-4 | **1390/1390** files resolve; no dead references |
| Duplicate PHP classes (FQCN) | AST-ish scan of `namespace`+`class/interface/trait/enum` | **None** |
| Duplicate routes (method + full path incl. prefixes) | route-file parser (428 routes) | **None** after fix (see TECHNICAL_DEBT.md #4) |
| Duplicate OpenAPI component schemas | YAML parse + key counting | **None** (181 schemas, all unique) |
| Identical OpenAPI path templates | param-normalized path comparison | **None** (243 paths) |
| Unresolved OpenAPI `$ref`s (schemas/responses/parameters) | regex vs component keys | **None** |
| Migration table-name / timestamp-prefix collisions | scan of `Schema::create` + filenames | **None** (94 tables, 41 files) |
| Domain event-name collisions | scan of `eventName()` return strings | **None** (42 classes / 42 distinct names) |
| Cross-bounded-context imports | scan for `use EruoFood\<Other>\…` outside `Shared`/`Contracts` | **None illegal** — only `EruoFood\Ai\Contracts` consumed |
| PSR-4 prefix/path uniqueness | composer.json inspection | **Unique** (19 prod, 17 dev) |
| Config files referenced | accessor scan (`config()`/`->get()`) | All 15 module configs referenced; none orphaned |
| Flutter dependency manifest | `pubspec.yaml` inspection | Valid (Dart SDK ≥3.5.0, Flutter ≥3.24.0; get_it/dio/dartz/bloc) |
| Flutter/Dart imports | static import-resolution scan | **137/137** Dart files resolve; no missing files or undeclared packages |
| composer.json PHP/Laravel/Pest versions | inspection | php ^8.4, laravel/framework ^12, pestphp/pest ^3.5, larastan ^3, pint ^1.18 — production-ready |
| Pest/PHPUnit config | `phpunit.xml`, `tests/Pest.php`, `tests/TestCase.php` inspection | Present and coherent (feature suite paths include all modules incl. Loyalty/Reviews) |

## ⚪ Not Validated (environment cannot execute)

| Check | Why | Evidence |
|---|---|---|
| **PHP Pest/PHPUnit test suites** (unit + feature) | `composer install` **cannot finalize** in this sandbox — several dist packages require GitHub authentication the proxy cannot provide, so the install aborts before generating the autoloader. `vendor/` remains a stub (`vendor/autoload.php` = 22-line placeholder, `vendor/bin/` absent, no `vendor/bin/pest`). | `composer install` reached 134/134 downloads then failed with `Could not authenticate against github.com` (AuthHelper.php:132), exit 100 |
| **Flutter/Dart tests** (`flutter test`) | Flutter and Dart toolchains are **not installed** (`which flutter dart` → not found). | Toolchain absent on PATH |

No claim is made that these suites pass. Their **static** structure was verified
(above): PHP test files are syntax-clean and reference resolvable symbols; Dart
test imports resolve. Executing them requires an environment with the Laravel
dependencies installed and the Flutter SDK present.

---

## How to execute the un-run suites (in a capable environment)

```bash
# PHP (needs composer deps installed — requires authenticated package downloads)
cd apps/api && composer install && vendor/bin/pest

# Flutter (needs the Flutter SDK)
cd apps/mobile && flutter pub get && flutter test
```
