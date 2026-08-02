/// A paginated collection page returned by the public API.
class Page<T> {
  Page({required this.data, required this.pagination, required this.version});

  final List<T> data;
  final Pagination pagination;
  final String version;
}

/// Standard pagination metadata.
class Pagination {
  Pagination({
    required this.page,
    required this.perPage,
    required this.total,
    required this.lastPage,
    required this.hasMore,
  });

  factory Pagination.fromJson(Map<String, dynamic> json) => Pagination(
        page: (json['page'] as num?)?.toInt() ?? 1,
        perPage: (json['per_page'] as num?)?.toInt() ?? 0,
        total: (json['total'] as num?)?.toInt() ?? 0,
        lastPage: (json['last_page'] as num?)?.toInt() ?? 1,
        hasMore: json['has_more'] as bool? ?? false,
      );

  final int page;
  final int perPage;
  final int total;
  final int lastPage;
  final bool hasMore;
}
