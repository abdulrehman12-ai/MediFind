import 'package:flutter/foundation.dart';

@immutable
class AppConfig {
  const AppConfig({required this.apiBaseUrl});

  final Uri apiBaseUrl;

  factory AppConfig.fromEnvironment() {
    const raw = String.fromEnvironment('API_BASE_URL');
    if (raw.isEmpty) {
      throw StateError('API_BASE_URL is required.');
    }
    return AppConfig.parse(raw);
  }

  factory AppConfig.parse(String raw) {
    final uri = Uri.parse(raw);
    assertSafeApiBaseUrl(uri, release: kReleaseMode);
    return AppConfig(apiBaseUrl: uri);
  }

  static void assertSafeApiBaseUrl(Uri uri, {required bool release}) {
    if (!uri.hasScheme || uri.host.isEmpty) {
      throw StateError('API_BASE_URL is not a valid absolute URL.');
    }
    if (!release) {
      return;
    }
    if (uri.scheme != 'https') {
      throw StateError('Release builds require HTTPS.');
    }
    final host = uri.host.toLowerCase();
    if (host == 'localhost' ||
        host == '127.0.0.1' ||
        host == '10.0.2.2' ||
        host == '::1') {
      throw StateError('Release builds must not use a loopback API URL.');
    }
  }
}
