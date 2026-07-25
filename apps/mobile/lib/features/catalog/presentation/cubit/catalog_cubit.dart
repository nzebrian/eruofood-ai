import 'package:flutter_bloc/flutter_bloc.dart';

import '../../domain/repositories/catalog_repository.dart';
import 'catalog_state.dart';

/// Drives the food catalogue browse/search screen.
class CatalogCubit extends Cubit<CatalogState> {
  CatalogCubit(this._repository) : super(const CatalogState());

  final CatalogRepository _repository;

  Future<void> load() => _fetch(query: state.query, region: state.region);

  Future<void> search(String query) => _fetch(query: query, region: state.region);

  Future<void> filterByRegion(String? region) => _fetch(query: state.query, region: region);

  Future<void> _fetch({required String query, String? region}) async {
    emit(state.copyWith(status: CatalogStatus.loading, query: query, region: region));
    final result = await _repository.foods(query: query, region: region);
    result.fold(
      (failure) => emit(state.copyWith(status: CatalogStatus.error, error: failure.message)),
      (foods) => emit(state.copyWith(status: CatalogStatus.loaded, foods: foods)),
    );
  }
}
