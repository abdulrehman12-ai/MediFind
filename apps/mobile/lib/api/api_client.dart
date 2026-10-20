import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

import 'api_problem.dart';

typedef TokenReader = Future<String?> Function();
typedef UnauthorizedHandler = Future<void> Function();

class ApiClient {
  ApiClient({
    required this.baseUrl,
    required http.Client httpClient,
    required this.readToken,
    this.onUnauthenticated,
    this.timeout = const Duration(seconds: 20),
  }) : _http = httpClient;

  final Uri baseUrl;
  final http.Client _http;
  final TokenReader readToken;
  final UnauthorizedHandler? onUnauthenticated;
  final Duration timeout;
  bool _handlingUnauthorized = false;

  Future<Map<String, dynamic>?> sendJson({
    required String method,
    required String path,
    Map<String, Object>? query,
    Map<String, dynamic>? body,
    Map<String, String>? headers,
    bool auth = true,
    int? expectedStatus,
  }) async {
    final response = await send(
      method: method,
      path: path,
      query: query,
      body: body == null ? null : jsonEncode(body),
      headers: {
        'Accept': 'application/json',
        if (body != null) 'Content-Type': 'application/json',
        ...?headers,
      },
      auth: auth,
    );
    if (expectedStatus != null && response.statusCode != expectedStatus) {
      throw problemFrom(response);
    }
    if (response.statusCode == 204) {
      return null;
    }
    if (response.statusCode >= 400) {
      throw problemFrom(response);
    }
    if (response.body.isEmpty) {
      return null;
    }
    final decoded = jsonDecode(response.body);
    if (decoded is! Map<String, dynamic>) {
      throw ApiProblem(
        statusCode: response.statusCode,
        code: 'invalid_response',
        message: 'The server returned an unexpected payload.',
      );
    }
    return decoded;
  }

  Future<http.Response> send({
    required String method,
    required String path,
    Map<String, Object>? query,
    String? body,
    Map<String, String>? headers,
    bool auth = true,
  }) async {
    final resolved = baseUrl.resolve(path);
    final uri = query == null || query.isEmpty
        ? resolved
        : resolved.replace(
            queryParameters: <String, dynamic>{
              ...resolved.queryParameters,
              ...query,
            },
          );
    final request = http.Request(method, uri);
    request.headers.addAll(headers ?? const {});
    // Never send a client-generated X-Request-Id.
    request.headers.remove('X-Request-Id');
    request.headers.remove('x-request-id');
    if (auth) {
      final token = await readToken();
      if (token != null && token.isNotEmpty) {
        request.headers['Authorization'] = 'Bearer $token';
      }
    }
    if (body != null) {
      request.body = body;
    }
    try {
      final streamed = await _http.send(request).timeout(timeout);
      final response = await http.Response.fromStream(streamed);
      if (auth &&
          response.statusCode == 401 &&
          onUnauthenticated != null &&
          !_handlingUnauthorized) {
        _handlingUnauthorized = true;
        try {
          await onUnauthenticated!();
        } finally {
          _handlingUnauthorized = false;
        }
      }
      return response;
    } on TimeoutException {
      throw ApiTransportException('timeout', 'The request timed out.');
    } on SocketException {
      throw ApiTransportException('offline', 'You appear to be offline.');
    }
  }

  Never throwFrom(http.Response response) {
    throw problemFrom(response);
  }

  ApiProblem problemFrom(http.Response response) {
    String code = 'http_${response.statusCode}';
    String message = 'The request failed.';
    Object? details;
    String? requestId = response.headers['x-request-id'];
    int? retryAfter;
    final rawRetry = response.headers['retry-after'];
    if (rawRetry != null) {
      retryAfter = int.tryParse(rawRetry);
    }
    if (response.body.isNotEmpty) {
      try {
        final decoded = jsonDecode(response.body);
        if (decoded is Map<String, dynamic>) {
          final error = decoded['error'];
          if (error is Map<String, dynamic>) {
            code = error['code'] as String? ?? code;
            message = error['message'] as String? ?? message;
            details = error['details'];
          }
          final meta = decoded['meta'];
          if (meta is Map<String, dynamic>) {
            requestId = meta['request_id'] as String? ?? requestId;
          }
        }
      } on FormatException {
        // Keep HTTP fallback codes. Do not log the body.
      }
    }
    if (response.statusCode == 401 && code == 'http_401') {
      code = 'unauthenticated';
    }
    return ApiProblem(
      statusCode: response.statusCode,
      code: code,
      message: message,
      details: details,
      retryAfterSeconds: retryAfter,
      requestId: requestId,
    );
  }
}
