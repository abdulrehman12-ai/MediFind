import 'dart:convert';

import 'package:clock/clock.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:medifind/bootstrap.dart';
import 'package:medifind/config/app_config.dart';
import 'package:medifind/location/location_source.dart';
import 'package:medifind/session.dart';

const _customerId = '11111111-1111-1111-1111-111111111111';
const _otherCustomerId = '22222222-2222-2222-2222-222222222222';
const _pharmacyId = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

String _customerJson({String id = _customerId}) {
  return jsonEncode({
    'id': id,
    'phone_masked': '+92300***4567',
    'full_name': null,
    'email': null,
    'role': 'customer',
    'status': 'active',
  });
}

String _pendingJson({String customerId = _customerId}) {
  return jsonEncode({
    'idempotency_key': 'IntentKey_1234567890ab',
    'cart_version': 1,
    'pharmacy_id': _pharmacyId,
    'customer_id': customerId,
  });
}

void main() {
  testWidgets('missing expiry is not authenticated and shows sign-in', (
    tester,
  ) async {
    final store = MemorySecureStore();
    await store.write(SessionController.tokenKey, 'secret-token');
    await store.write(SessionController.customerKey, _customerJson());
    final session = SessionController(store);
    final app = await bootstrapMedifind(
      config: AppConfig.parse('http://10.0.2.2:18080'),
      session: session,
      location: DeniedLocationSource(),
      httpClient: MockClient((request) async => http.Response('{}', 500)),
    );
    await tester.pumpWidget(app);
    await tester.pumpAndSettle();
    expect(session.isSignedIn, isFalse);
    expect(await store.read(SessionController.tokenKey), isNull);
    expect(find.text('Request code'), findsOneWidget);
  });

  testWidgets('empty expiry is not authenticated and shows sign-in', (
    tester,
  ) async {
    final store = MemorySecureStore();
    await store.write(SessionController.tokenKey, 'secret-token');
    await store.write(SessionController.expiryKey, '');
    await store.write(SessionController.customerKey, _customerJson());
    final session = SessionController(store);
    final app = await bootstrapMedifind(
      config: AppConfig.parse('http://10.0.2.2:18080'),
      session: session,
      location: DeniedLocationSource(),
      httpClient: MockClient((request) async => http.Response('{}', 500)),
    );
    await tester.pumpWidget(app);
    await tester.pumpAndSettle();
    expect(session.isSignedIn, isFalse);
    expect(find.text('Request code'), findsOneWidget);
  });

  testWidgets('malformed expiry is not authenticated and shows sign-in', (
    tester,
  ) async {
    final store = MemorySecureStore();
    await store.write(SessionController.tokenKey, 'secret-token');
    await store.write(SessionController.expiryKey, 'not-an-instant');
    await store.write(SessionController.customerKey, _customerJson());
    final session = SessionController(store);
    final app = await bootstrapMedifind(
      config: AppConfig.parse('http://10.0.2.2:18080'),
      session: session,
      location: DeniedLocationSource(),
      httpClient: MockClient((request) async => http.Response('{}', 500)),
    );
    await tester.pumpWidget(app);
    await tester.pumpAndSettle();
    expect(session.isSignedIn, isFalse);
    expect(find.text('Request code'), findsOneWidget);
  });

  testWidgets(
    'malformed customer JSON is not authenticated and shows sign-in',
    (tester) async {
      final store = MemorySecureStore();
      await store.write(SessionController.tokenKey, 'secret-token');
      await store.write(
        SessionController.expiryKey,
        DateTime.utc(2027, 1, 1).toIso8601String(),
      );
      await store.write(SessionController.customerKey, '{not-json');
      final session = SessionController(store);
      final app = await bootstrapMedifind(
        config: AppConfig.parse('http://10.0.2.2:18080'),
        session: session,
        location: DeniedLocationSource(),
        httpClient: MockClient((request) async => http.Response('{}', 500)),
      );
      await tester.pumpWidget(app);
      await tester.pumpAndSettle();
      expect(session.isSignedIn, isFalse);
      expect(session.customer, isNull);
      expect(find.text('Request code'), findsOneWidget);
    },
  );

  testWidgets('wrong-shaped customer JSON is not authenticated', (
    tester,
  ) async {
    final store = MemorySecureStore();
    await store.write(SessionController.tokenKey, 'secret-token');
    await store.write(
      SessionController.expiryKey,
      DateTime.utc(2027, 1, 1).toIso8601String(),
    );
    await store.write(SessionController.customerKey, jsonEncode(['nope']));
    final session = SessionController(store);
    final app = await bootstrapMedifind(
      config: AppConfig.parse('http://10.0.2.2:18080'),
      session: session,
      location: DeniedLocationSource(),
      httpClient: MockClient((request) async => http.Response('{}', 500)),
    );
    await tester.pumpWidget(app);
    await tester.pumpAndSettle();
    expect(session.isSignedIn, isFalse);
    expect(find.text('Request code'), findsOneWidget);
  });

  testWidgets('expired session loads to sign-in and keeps owned pending', (
    tester,
  ) async {
    final store = MemorySecureStore();
    await store.write(SessionController.tokenKey, 'secret-token');
    await store.write(
      SessionController.expiryKey,
      DateTime.utc(2026, 10, 2, 11).toIso8601String(),
    );
    await store.write(SessionController.customerKey, _customerJson());
    await store.write(SessionController.pendingKey, _pendingJson());
    final session = SessionController(store);
    await withClock(Clock.fixed(DateTime.utc(2026, 10, 2, 12)), () async {
      final app = await bootstrapMedifind(
        config: AppConfig.parse('http://10.0.2.2:18080'),
        session: session,
        location: DeniedLocationSource(),
        httpClient: MockClient((request) async => http.Response('{}', 500)),
      );
      await tester.pumpWidget(app);
      await tester.pumpAndSettle();
      expect(session.isSignedIn, isFalse);
      expect(session.pendingSubmit?.customerId, _customerId);
      expect(find.text('Request code'), findsOneWidget);
    });
  });

  testWidgets('malformed pending JSON is deleted and startup still signs out', (
    tester,
  ) async {
    final store = MemorySecureStore();
    await store.write(SessionController.pendingKey, '{bad');
    final session = SessionController(store);
    final app = await bootstrapMedifind(
      config: AppConfig.parse('http://10.0.2.2:18080'),
      session: session,
      location: DeniedLocationSource(),
      httpClient: MockClient((request) async => http.Response('{}', 500)),
    );
    await tester.pumpWidget(app);
    await tester.pumpAndSettle();
    expect(session.pendingSubmit, isNull);
    expect(await store.read(SessionController.pendingKey), isNull);
    expect(find.text('Request code'), findsOneWidget);
  });

  testWidgets('legacy pending without owner is deleted', (tester) async {
    final store = MemorySecureStore();
    await store.write(
      SessionController.pendingKey,
      jsonEncode({
        'idempotency_key': 'LegacyKey_1234567890ab',
        'cart_version': 1,
        'pharmacy_id': _pharmacyId,
      }),
    );
    final session = SessionController(store);
    await session.load();
    expect(session.pendingSubmit, isNull);
    expect(await store.read(SessionController.pendingKey), isNull);
    expect(session.loaded, isTrue);
    expect(session.isSignedIn, isFalse);
  });

  test('secure-store read failure does not crash or authenticate', () async {
    final session = SessionController(_ThrowingReadStore());
    await session.load();
    expect(session.loaded, isTrue);
    expect(session.isSignedIn, isFalse);
    expect(session.pendingSubmit, isNull);
  });

  test(
    'signed-in session drops a pending submit owned by someone else',
    () async {
      final store = MemorySecureStore();
      await store.write(SessionController.tokenKey, 'secret-token');
      await store.write(
        SessionController.expiryKey,
        DateTime.utc(2027, 1, 1).toIso8601String(),
      );
      await store.write(SessionController.customerKey, _customerJson());
      await store.write(
        SessionController.pendingKey,
        _pendingJson(customerId: _otherCustomerId),
      );
      final session = SessionController(store);
      await session.load();
      expect(session.isSignedIn, isTrue);
      expect(session.pendingSubmit, isNull);
      expect(session.canRetryPendingFor(_customerId), isFalse);
    },
  );
}

class _ThrowingReadStore implements SecureStore {
  @override
  Future<void> delete(String key) async {}

  @override
  Future<void> deleteAll() async {}

  @override
  Future<String?> read(String key) async {
    throw StateError('read-failed');
  }

  @override
  Future<void> write(String key, String value) async {}
}
