/// Thrown for any non-2xx response; carries the standard error envelope fields.
class EruoFoodApiException implements Exception {
  EruoFoodApiException(this.status, this.code, this.message, [this.details]);

  final int status;
  final String code;
  final String message;
  final Object? details;

  @override
  String toString() => 'EruoFoodApiException($status $code): $message';
}
