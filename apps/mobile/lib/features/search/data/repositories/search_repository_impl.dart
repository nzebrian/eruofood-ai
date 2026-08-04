import 'package:dartz/dartz.dart';
import 'package:dio/dio.dart';

import '../../../../core/error/failure.dart';
import '../../../../core/error/failures.dart';
import '../../domain/entities/search_entities.dart';
import '../../domain/repositories/search_repository.dart';
import '../datasources/search_remote_data_source.dart';

class SearchRepositoryImpl implements SearchRepository {
  SearchRepositoryImpl(this._remote);

  final SearchRemoteDataSource _remote;

  @override
  Future<Either<Failure, SearchResultsView>> search(
    String term,
    String type,
    String sort,
    SearchFiltersView filters,
  ) =>
      _guard(() => _remote.search(term, type, sort, filters));

  @override
  Future<Either<Failure, List<String>>> autocomplete(String term, String type) =>
      _guard(() => _remote.autocomplete(term, type));

  @override
  Future<Either<Failure, List<SearchDocumentView>>> recommendations(String kind, String type) =>
      _guard(() => _remote.recommendations(kind, type));

  Future<Either<Failure, T>> _guard<T>(Future<T> Function() call) async {
    try {
      return Right<Failure, T>(await call());
    } on DioException catch (e) {
      final dynamic data = e.response?.data;
      final message = data is Map<String, dynamic>
          ? (data['error']?['message']?.toString() ?? e.message ?? 'Network error.')
          : (e.message ?? 'Network error.');
      return Left<Failure, T>(ServerFailure(message));
    }
  }
}
