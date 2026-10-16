class ApiProblem implements Exception {
  ApiProblem({
    required this.statusCode,
    required this.code,
    required this.message,
    this.details,
    this.retryAfterSeconds,
    this.requestId,
  });

  final int statusCode;
  final String code;
  final String message;
  final Object? details;
  final int? retryAfterSeconds;
  final String? requestId;

  bool get isUnauthenticated => statusCode == 401 || code == 'unauthenticated';

  String get userMessage {
    return switch (code) {
      'otp_cooldown' => 'Wait before requesting another code.',
      'otp_rate_limited' => 'Too many OTP requests. Try again later.',
      'otp_invalid' => 'That code is invalid.',
      'otp_in_progress' => 'Verification is already in progress.',
      'otp_unavailable' => 'Sign-in is temporarily unavailable.',
      'otp_delivery_unavailable' => 'The code could not be sent. Try again.',
      'account_inactive' => 'This account is inactive.',
      'account_role_forbidden' =>
        'Customer sign-in is not available for this phone number.',
      'forbidden' => 'This action is not allowed.',
      'validation_failed' => 'Check the highlighted fields and try again.',
      'not_found' => 'That record was not found.',
      'address_limit' => 'You have reached the address limit.',
      'address_conflict' =>
        'The default address could not be saved. Reload and try again.',
      'address_unavailable' => 'Addresses are temporarily unavailable.',
      'medicine_unavailable' =>
        'One or more selected medicines are not available for pickup.',
      'pharmacy_unavailable' => 'That pharmacy cannot accept a pickup order.',
      'cart_stale' => 'The cart changed. Reload it and try again.',
      'idempotency_conflict' => 'This submit key was already used for a different cart. Confirm a new order to continue.',
      'request_not_completed' =>
        'The server did not finish. Retry with the same key.',
      'order_estimate_too_large' =>
        'This order estimate is too large to submit.',
      'order_not_cancellable' => 'This order can no longer be cancelled.',
      'quote_stale' => 'Accept the current quote version.',
      'quote_expired' =>
        'This quote has expired. Ask the pharmacy for a new quote.',
      'prescription_unavailable' =>
        'This prescription cannot be used for this order.',
      'prescription_unusable' => 'This order cannot continue because the prescription is no longer valid.',
      'timeout' =>
        'The request timed out. Check your connection and try again.',
      'offline' => 'You appear to be offline.',
      _ => message.isEmpty ? 'The request failed ($code).' : message,
    };
  }
}

class ApiTransportException implements Exception {
  ApiTransportException(this.code, this.message);

  final String code;
  final String message;
}
