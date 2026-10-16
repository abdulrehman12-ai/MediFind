import 'dart:convert';

import 'package:clock/clock.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:medifind/api/api_client.dart';
import 'package:medifind/api/api_problem.dart';
import 'package:medifind/models.dart';
import 'package:medifind/repositories.dart';
import 'package:medifind/session.dart';

void main() {
  test('session expiry clears the token without a refresh flow', () async {
    final store = MemorySecureStore();
    final session = SessionController(store);
    await withClock(Clock.fixed(DateTime.utc(2026, 10, 2, 12)), () async {
      await session.saveAuth(
        token: 'secret-token',
        expires: DateTime.utc(2026, 10, 2, 11),
        signedIn: Customer(
          id: '11111111-1111-1111-1111-111111111111',
          phoneMasked: '+92300***4567',
          fullName: null,
          email: null,
          role: 'customer',
          status: 'active',
        ),
      );
      await session.load();
      expect(session.isSignedIn, isFalse);
      expect(await store.read('access_token'), isNull);
    });
  });

  test(
    'preserves one idempotency key until the submit is reconciled',
    () async {
      final session = SessionController(MemorySecureStore());
      await session.saveAuth(
        token: 'secret-token',
        expires: DateTime.utc(2027, 1, 1),
        signedIn: _customer(),
      );
      final pending = PendingSubmit(
        idempotencyKey: 'IntentKey_1234567890ab',
        cartVersion: 1,
        pharmacyId: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        customerId: _customer().id,
      );
      await session.savePending(pending);
      await session.load();
      expect(session.isSignedIn, isTrue);
      expect(session.pendingSubmit?.idempotencyKey, pending.idempotencyKey);
      expect(session.pendingSubmit?.customerId, _customer().id);
    },
  );

  test('maps material API problems from fake HTTP', () async {
    Future<void> expectCode(int status, String body, String code) async {
      final client = ApiClient(
        baseUrl: Uri.parse('http://10.0.2.2:18080'),
        httpClient: MockClient(
          (request) async => http.Response(
            body,
            status,
            headers: {'content-type': 'application/json'},
          ),
        ),
        readToken: () async => 'token',
      );
      try {
        await AuthRepository(client).requestOtp('03001234567');
        fail('expected problem');
      } on ApiProblem catch (error) {
        expect(error.code, code);
      }
    }

    await expectCode(
      429,
      jsonEncode({
        'error': {'code': 'otp_cooldown', 'message': 'wait'},
      }),
      'otp_cooldown',
    );
    await expectCode(
      401,
      jsonEncode({
        'error': {'code': 'unauthenticated', 'message': 'no'},
      }),
      'unauthenticated',
    );
    await expectCode(
      403,
      jsonEncode({
        'error': {'code': 'account_inactive', 'message': 'no'},
      }),
      'account_inactive',
    );
    await expectCode(
      404,
      jsonEncode({
        'error': {'code': 'not_found', 'message': 'no'},
      }),
      'not_found',
    );
    await expectCode(
      409,
      jsonEncode({
        'error': {'code': 'cart_stale', 'message': 'no'},
      }),
      'cart_stale',
    );
    await expectCode(
      422,
      jsonEncode({
        'error': {'code': 'validation_failed', 'message': 'no'},
      }),
      'validation_failed',
    );
    await expectCode(
      503,
      jsonEncode({
        'error': {'code': 'otp_unavailable', 'message': 'no'},
      }),
      'otp_unavailable',
    );
  });

  test('availability encodes medicine_ids as repeated bracket keys', () async {
    http.Request? seen;
    final client = ApiClient(
      baseUrl: Uri.parse('http://10.0.2.2:18080'),
      httpClient: MockClient((request) async {
        seen = request;
        return http.Response(
          jsonEncode({
            'data': <dynamic>[],
            'meta': {
              'request_id': '11111111-1111-1111-1111-111111111111',
              'confirmation':
                  'Availability is straight-line distance, not travel time.',
              'has_more': false,
              'page': 1,
              'per_page': 20,
              'radius_meters': 5000,
              'freshness_hours': 24,
              'distance_method': 'straight_line',
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
      readToken: () async => 'token',
    );
    await DiscoveryRepository(client).availability(
      medicineIds: ['cccccccc-cccc-cccc-cccc-cccccccccccc'],
      addressId: 'dddddddd-dddd-dddd-dddd-dddddddddddd',
    );
    expect(seen!.url.query, contains('medicine_ids%5B%5D='));
    expect(seen!.url.queryParameters.containsKey('address_id'), isTrue);
  });

  test('does not send a client request id', () async {
    http.Request? seen;
    final client = ApiClient(
      baseUrl: Uri.parse('http://10.0.2.2:18080'),
      httpClient: MockClient((request) async {
        seen = request;
        return http.Response(
          jsonEncode({
            'data': {'challenge_id': 'c', 'expires_in': 300, 'retry_after': 60},
            'meta': {'request_id': 'server'},
          }),
          202,
        );
      }),
      readToken: () async => null,
    );
    await AuthRepository(client).requestOtp('03001234567');
    expect(seen!.headers.containsKey('X-Request-Id'), isFalse);
    expect(seen!.headers.containsKey('x-request-id'), isFalse);
  });

  test('200 submit replay is the same order payload', () async {
    var calls = 0;
    final client = ApiClient(
      baseUrl: Uri.parse('http://10.0.2.2:18080'),
      httpClient: MockClient((request) async {
        calls += 1;
        return http.Response(jsonEncode(_orderEnvelope()), 200);
      }),
      readToken: () async => 'token',
    );
    final first = await OrderRepository(client)
        .submit(cartVersion: 1, idempotencyKey: 'SameIntentKey_1234');
    final second = await OrderRepository(client)
        .submit(cartVersion: 1, idempotencyKey: 'SameIntentKey_1234');
    expect(first.statusCode, 200);
    expect(second.order.id, first.order.id);
    expect(calls, 2);
  });

  test('parses unknown timeline events without failing', () {
    final order = CustomerOrder.fromJson(
      _orderEnvelope()['data'] as Map<String, dynamic>,
    );
    expect(order.timeline.first.event, 'future_event');
    expect(order.pickupWindow!.graceSeconds, 7200);
  });

  test('401 clears auth and keeps a pending submit key', () async {
    final session = SessionController(MemorySecureStore());
    await session.saveAuth(
      token: 'secret-token',
      expires: DateTime.utc(2027, 1, 1),
      signedIn: _customer(),
    );
    await session.savePending(
      PendingSubmit(
        idempotencyKey: 'KeepKey_1234567890abcd',
        cartVersion: 2,
        pharmacyId: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        customerId: _customer().id,
      ),
    );
    final client = ApiClient(
      baseUrl: Uri.parse('http://10.0.2.2:18080'),
      httpClient: MockClient(
        (request) async => http.Response(
          jsonEncode({
            'error': {'code': 'unauthenticated', 'message': 'no'},
          }),
          401,
          headers: {'content-type': 'application/json'},
        ),
      ),
      readToken: () async => session.accessToken,
      onUnauthenticated: () => session.clearAuth(keepPending: true),
    );
    try {
      await ProfileRepository(client).me();
      fail('expected unauthenticated');
    } on ApiProblem catch (error) {
      expect(error.isUnauthenticated, isTrue);
    }
    expect(session.isSignedIn, isFalse);
    expect(session.pendingSubmit?.idempotencyKey, 'KeepKey_1234567890abcd');
  });

  test('logout clearAuth drops the pending submit key', () async {
    final session = SessionController(MemorySecureStore());
    await session.saveAuth(
      token: 'secret-token',
      expires: DateTime.utc(2027, 1, 1),
      signedIn: _customer(),
    );
    await session.savePending(
      PendingSubmit(
        idempotencyKey: 'DropKey_1234567890abcd',
        cartVersion: 1,
        pharmacyId: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        customerId: _customer().id,
      ),
    );
    await session.clearAuth();
    expect(session.pendingSubmit, isNull);
  });

  test('refuses to save a pending submit without a signed-in owner', () async {
    final session = SessionController(MemorySecureStore());
    await session.savePending(
      PendingSubmit(
        idempotencyKey: 'NoOwnerKey_1234567890',
        cartVersion: 1,
        pharmacyId: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        customerId: _customer().id,
      ),
    );
    expect(session.pendingSubmit, isNull);
  });

  test('same-customer reauthentication keeps pending recovery', () async {
    final session = SessionController(MemorySecureStore());
    await session.saveAuth(
      token: 'first-token',
      expires: DateTime.utc(2027, 1, 1),
      signedIn: _customer(),
    );
    await session.savePending(
      PendingSubmit(
        idempotencyKey: 'KeepKey_same_customer1',
        cartVersion: 2,
        pharmacyId: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        customerId: _customer().id,
      ),
    );
    await session.clearAuth(keepPending: true);
    expect(session.isSignedIn, isFalse);
    expect(session.pendingSubmit?.customerId, _customer().id);
    await session.saveAuth(
      token: 'second-token',
      expires: DateTime.utc(2027, 6, 1),
      signedIn: _customer(),
    );
    expect(session.pendingSubmit?.idempotencyKey, 'KeepKey_same_customer1');
    expect(session.canRetryPendingFor(_customer().id), isTrue);
  });

  test('different-customer login deletes a pending submit', () async {
    final session = SessionController(MemorySecureStore());
    await session.saveAuth(
      token: 'first-token',
      expires: DateTime.utc(2027, 1, 1),
      signedIn: _customer(),
    );
    await session.savePending(
      PendingSubmit(
        idempotencyKey: 'OtherKey_1234567890ab',
        cartVersion: 2,
        pharmacyId: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        customerId: _customer().id,
      ),
    );
    await session.clearAuth(keepPending: true);
    await session.saveAuth(
      token: 'other-token',
      expires: DateTime.utc(2027, 6, 1),
      signedIn: _customer(id: '22222222-2222-2222-2222-222222222222'),
    );
    expect(session.pendingSubmit, isNull);
    expect(
      session.canRetryPendingFor('22222222-2222-2222-2222-222222222222'),
      isFalse,
    );
  });

  test('does not retry a pending submit for a mismatched owner', () async {
    final session = SessionController(MemorySecureStore());
    await session.saveAuth(
      token: 'secret-token',
      expires: DateTime.utc(2027, 1, 1),
      signedIn: _customer(),
    );
    expect(session.canRetryPendingFor(_customer().id), isFalse);
    session.pendingSubmit = PendingSubmit(
      idempotencyKey: 'MismatchKey_12345678',
      cartVersion: 1,
      pharmacyId: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
      customerId: '22222222-2222-2222-2222-222222222222',
    );
    expect(session.canRetryPendingFor(_customer().id), isFalse);
    await session.savePending(session.pendingSubmit!);
    expect(session.pendingSubmit?.customerId, isNot(_customer().id));
    expect(session.canRetryPendingFor(_customer().id), isFalse);
  });

  test('order history omits empty cursors and reads has_more', () async {
    http.Request? seen;
    final client = ApiClient(
      baseUrl: Uri.parse('http://10.0.2.2:18080'),
      httpClient: MockClient((request) async {
        seen = request;
        return http.Response(
          jsonEncode({
            'data': [_orderEnvelope()['data']],
            'meta': {
              'request_id': '11111111-1111-1111-1111-111111111111',
              'has_more': true,
              'next_cursor': 'opaque-cursor',
              'per_page': 20,
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
      readToken: () async => 'token',
    );
    final first = await OrderRepository(client).list();
    expect(seen!.url.queryParameters.containsKey('cursor'), isFalse);
    expect(first.hasMore, isTrue);
    expect(first.nextCursor, 'opaque-cursor');
    expect(first.orders, hasLength(1));

    await OrderRepository(client).list(cursor: 'opaque-cursor');
    expect(seen!.url.queryParameters['cursor'], 'opaque-cursor');
  });

  test('parses address list from the nested addresses envelope', () async {
    final client = ApiClient(
      baseUrl: Uri.parse('http://10.0.2.2:18080'),
      httpClient: MockClient((request) async {
        return http.Response(
          jsonEncode({
            'data': {
              'addresses': [
                {
                  'id': 'dddddddd-dddd-dddd-dddd-dddddddddddd',
                  'label': 'Home',
                  'address_text': 'Susan Road',
                  'city': 'Faisalabad',
                  'latitude': '31.450400',
                  'longitude': '73.135000',
                  'is_default': true,
                },
              ],
            },
            'meta': {'request_id': '11111111-1111-1111-1111-111111111111'},
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
      readToken: () async => 'token',
    );
    final rows = await ProfileRepository(client).addresses();
    expect(rows, hasLength(1));
    expect(rows.first.isDefault, isTrue);
  });

  test('cart replace maps cart_stale from fake HTTP', () async {
    final client = ApiClient(
      baseUrl: Uri.parse('http://10.0.2.2:18080'),
      httpClient: MockClient(
        (request) async => http.Response(
          jsonEncode({
            'error': {'code': 'cart_stale', 'message': 'stale'},
          }),
          409,
          headers: {'content-type': 'application/json'},
        ),
      ),
      readToken: () async => 'token',
    );
    try {
      await CartRepository(client).replace({
        'version': 1,
        'pharmacy_id': 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        'mode': 'catalog_items',
        'items': [
          {
            'medicine_id': 'cccccccc-cccc-cccc-cccc-cccccccccccc',
            'quantity': 1,
          },
        ],
      });
      fail('expected cart_stale');
    } on ApiProblem catch (error) {
      expect(error.code, 'cart_stale');
    }
  });

  test('general-use maps medicine_unavailable', () async {
    final client = ApiClient(
      baseUrl: Uri.parse('http://10.0.2.2:18080'),
      httpClient: MockClient(
        (request) async => http.Response(
          jsonEncode({
            'error': {'code': 'not_found', 'message': 'missing'},
          }),
          404,
          headers: {'content-type': 'application/json'},
        ),
      ),
      readToken: () async => 'token',
    );
    try {
      await DiscoveryRepository(client)
          .generalUse('cccccccc-cccc-cccc-cccc-cccccccccccc');
      fail('expected not_found');
    } on ApiProblem catch (error) {
      expect(error.code, 'not_found');
    }
  });
}

Customer _customer({String id = '11111111-1111-1111-1111-111111111111'}) {
  return Customer(
    id: id,
    phoneMasked: '+92300***4567',
    fullName: null,
    email: null,
    role: 'customer',
    status: 'active',
  );
}

Map<String, dynamic> _orderEnvelope() {
  return {
    'data': {
      'id': 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
      'reference': 'MFTEST001',
      'pharmacy_id': 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
      'status': 'pending_pharmacy_review',
      'status_text': 'Requested',
      'request_mode': 'catalog_items',
      'prescription_id': null,
      'prescription_usable': true,
      'payment_method': 'cash_on_pickup',
      'payment_status': 'unpaid',
      'amounts_are_estimates': true,
      'estimate_total_paisa': 1500,
      'confirmed_total_paisa': null,
      'pickup_window': {
        'starts_at': '2026-10-02T04:00:00.000000Z',
        'ends_at': '2026-10-02T12:00:00.000000Z',
        'collect_until': '2026-10-02T14:00:00.000000Z',
        'grace_seconds': 7200,
      },
      'cash_collected_paisa': null,
      'notices': ['Requested'],
      'items': <Map<String, dynamic>>[],
      'reviewed_lines': <Map<String, dynamic>>[],
      'quote': null,
      'timeline': [
        {
          'status': 'pending_pharmacy_review',
          'event': 'future_event',
          'at': '2026-10-02T04:00:00.000000Z',
          'actor': 'system',
        },
      ],
      'created_at': '2026-10-02T04:00:00.000000Z',
    },
    'meta': {'request_id': '11111111-1111-1111-1111-111111111111'},
  };
}
