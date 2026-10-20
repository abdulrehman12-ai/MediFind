import 'package:flutter_test/flutter_test.dart';
import 'package:medifind/config/app_config.dart';
import 'package:medifind/format.dart';

void main() {
  test('parses both timestamp forms as UTC', () {
    expect(parseInstant('2026-10-02T04:00:00.000000Z').isUtc, isTrue);
    expect(
      parseInstant('2026-10-02T04:00:00+00:00').toUtc(),
      DateTime.utc(2026, 10, 2, 4),
    );
  });

  test('formats pickup times in Asia/Karachi', () {
    expect(
      formatKarachi(DateTime.utc(2026, 10, 2, 4, 0, 0)),
      '2026-10-02 09:00 Asia/Karachi',
    );
  });

  test(
    'formats integer paisa without inventing rupee fractions from strings',
    () {
      expect(formatPaisa(1500), 'Rs 15.00');
      expect(formatPaisa(0), 'Rs 0.00');
    },
  );

  test('debug config accepts emulator isolated URL', () {
    final config = AppConfig.parse('http://10.0.2.2:18080');
    expect(config.apiBaseUrl.host, '10.0.2.2');
  });

  test('release configuration rejects loopback HTTP', () {
    expect(
      () => AppConfig.assertSafeApiBaseUrl(
        Uri.parse('http://10.0.2.2:18080'),
        release: true,
      ),
      throwsStateError,
    );
    expect(
      () => AppConfig.assertSafeApiBaseUrl(
        Uri.parse('http://127.0.0.1:18080'),
        release: true,
      ),
      throwsStateError,
    );
    AppConfig.assertSafeApiBaseUrl(
      Uri.parse('https://api.example.test'),
      release: true,
    );
  });
}
