import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:get_it/get_it.dart';

import '../../core/network/api_client.dart';
import '../../core/storage/token_store.dart';
import 'data/datasources/auth_remote_data_source.dart';
import 'data/repositories/auth_repository_impl.dart';
import 'domain/repositories/auth_repository.dart';
import 'domain/usecases/auth_usecases.dart';
import 'presentation/cubit/auth_cubit.dart';

/// Registers the auth feature's dependency graph. Called from the core injector.
void registerAuthFeature(GetIt sl) {
  // Infrastructure
  sl.registerLazySingleton<TokenStore>(() => SecureTokenStore(const FlutterSecureStorage()));

  // Data
  sl.registerLazySingleton<AuthRemoteDataSource>(() => AuthRemoteDataSource(sl<ApiClient>()));
  sl.registerLazySingleton<AuthRepository>(
    () => AuthRepositoryImpl(sl<AuthRemoteDataSource>(), sl<TokenStore>()),
  );

  // Use cases
  sl.registerLazySingleton(() => LoginUseCase(sl<AuthRepository>()));
  sl.registerLazySingleton(() => RegisterUseCase(sl<AuthRepository>()));
  sl.registerLazySingleton(() => LogoutUseCase(sl<AuthRepository>()));
  sl.registerLazySingleton(() => GetCurrentUserUseCase(sl<AuthRepository>()));

  // Presentation
  sl.registerFactory(
    () => AuthCubit(
      login: sl<LoginUseCase>(),
      register: sl<RegisterUseCase>(),
      logout: sl<LogoutUseCase>(),
      currentUser: sl<GetCurrentUserUseCase>(),
      repository: sl<AuthRepository>(),
    ),
  );
}
