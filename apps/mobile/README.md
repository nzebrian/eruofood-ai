# EruoFood AI — Mobile (Flutter)

Feature-first **Clean Architecture** client for iOS & Android.

## Structure

```
lib/
├── main.dart              # Entry point: load .env, wire DI, run app
├── app.dart               # Root MaterialApp / theming
├── core/                  # Cross-cutting infrastructure
│   ├── config/            #   AppConfig (typed runtime config)
│   ├── di/                #   get_it service locator (injector.dart)
│   ├── network/           #   ApiClient (Dio wrapper)
│   ├── theme/             #   Material 3 theme tokens
│   └── error/             #   Failure base type (for Either<Failure, T>)
├── features/              # One folder per feature, each with:
│   └── <feature>/
│       ├── domain/        #   entities, repository interfaces, use cases
│       ├── data/          #   models, data sources, repository impls
│       └── presentation/  #   bloc/cubit, pages, widgets
└── shared/                # Reusable widgets/utilities across features
```

## Platform folders

`android/` and `ios/` exist and are committed, generated in M31 by:

```bash
flutter create --org ai.eruofood --project-name eruofood --platforms=android,ios .
```

Those two are scaffolded because those two are what `GA Flutter Certification`
builds. `web/`, `macos/`, `linux/` and `windows/` are deliberately absent —
forty-odd files nothing compiles or verifies is not a foundation, it is a
liability, and `verify_platform_foundation.sh` fails if one appears.

The command does not overwrite `lib/`, `test/`, `pubspec.yaml` or
`analysis_options.yaml`. It *does* run an implicit `pub get`, which on a first
run rewrote six transitive pins in `pubspec.lock`; restoring the lockfile and
re-running `flutter pub get` left it byte-identical, so the committed lock is
reproducible under Flutter 3.47.1 rather than merely old. The validator asserts
that byte-identity on every run.

Two generated labels are edited after generation, because `flutter create`
derives them from the project name and neither is the product's name:

| File | Generated | Committed |
|---|---|---|
| `android/app/src/main/AndroidManifest.xml` | `android:label="eruofood"` | `EruoFood AI` |
| `ios/Runner/Info.plist` | `CFBundleDisplayName` = `Eruofood` | `EruoFood AI` |

A regeneration on a later SDK silently reverts both, which is why the validator
checks them rather than trusting the diff.

### Validating the foundation

```bash
apps/mobile/scripts/verify_platform_foundation.sh        # 38 checks
apps/mobile/scripts/m31_platform_negative_control.sh     # 32 controls
```

The first checks the platform projects, identifiers, branding, the dependency
baseline and that no signing or service-credential file is committable. The
second breaks each of those in a throwaway copy and proves the first notices —
including a control that an *untouched* copy still passes, so a validator that
rejects everything cannot pass for one that works.

Both run automatically from M36: the control suite is a step in the required
`CI · Workflow Integrity` check, so a broken invariant fails a pull request
instead of waiting for somebody to remember the command.

## Changing a mobile dependency

M31 hash-pinned `pubspec.yaml` and `pubspec.lock` outright. That stopped
accidental regeneration drift — `flutter create` runs an implicit resolve, and
it rewrote six transitive pins the first time it ran here — and it also stopped
every deliberate bump: five Dependabot pull requests were failing for no reason
other than doing their job.

M36 replaced the pin with a baseline you can move, deliberately:

```bash
# 1. change the dependency in pubspec.yaml, then resolve it
flutter pub get

# 2. record the decision — in the SAME commit
apps/mobile/scripts/refresh_mobile_dependency_baseline.sh

# 3. confirm
apps/mobile/scripts/verify_platform_foundation.sh
```

`--check` reports whether the baseline is stale without writing anything, and is
safe to run in CI. The refresh command itself **refuses to run when `$CI` is
set**: a baseline that refreshes itself is not a baseline.

The manifest records dependency **names and constraints**, not just hashes, so
the diff shows `get_it: ^8.0.0 -> ^9.0.0` and a reviewer can judge it. Updating
the hashes is not enough on its own — section E2 re-derives the dependency sets
from `pubspec.yaml` and `pubspec.lock` and compares them in both directions on
every run, so an incoherent pair fails whether or not the baseline was
refreshed. A stray `flutter pub get` that rewrites only the lockfile still
fails, which was the original point.

`analysis_options.yaml` stays hash-pinned outright. Nothing in normal
development should change it.

## Commands

```bash
flutter pub get          # install dependencies
flutter analyze          # static analysis (see analysis_options.yaml)
flutter test             # run tests
flutter run              # launch on a device/emulator
```

## Notes

- No business features, auth, recipes, or AI in this phase — foundation only.
- Copy `.env.example` to `.env` before running; it is bundled as an asset.
