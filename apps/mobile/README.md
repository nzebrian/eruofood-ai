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

`android/`, `ios/`, `web/`, etc. are generated once Flutter is installed:

```bash
flutter create --org ai.eruofood --project-name eruofood .
```

That command scaffolds the native host projects **without** overwriting the
committed `lib/`, `test/`, `pubspec.yaml`, or `analysis_options.yaml`.

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
