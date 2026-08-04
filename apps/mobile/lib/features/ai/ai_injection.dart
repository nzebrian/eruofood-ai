import 'package:get_it/get_it.dart';

import '../../core/network/api_client.dart';
import 'data/datasources/ai_remote_data_source.dart';
import 'data/repositories/ai_repository_impl.dart';
import 'domain/repositories/ai_repository.dart';
import 'presentation/cubit/chat_cubit.dart';

/// Registers the AI feature's dependency graph.
void registerAiFeature(GetIt sl) {
  sl.registerLazySingleton<AiRemoteDataSource>(() => AiRemoteDataSource(sl<ApiClient>()));
  sl.registerLazySingleton<AiRepository>(() => AiRepositoryImpl(sl<AiRemoteDataSource>()));
  sl.registerFactory(() => ChatCubit(sl<AiRepository>()));
}
