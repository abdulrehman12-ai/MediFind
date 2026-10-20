import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:integration_test/integration_test.dart';
import 'package:medifind/bootstrap.dart';
import 'package:medifind/config/app_config.dart';
import 'package:medifind/location/location_source.dart';
import 'package:medifind/repositories.dart';
import 'package:medifind/session.dart';
import 'package:medifind/ui/app.dart';
import 'package:provider/provider.dart';

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets(
    'OTP search availability catalog cart idempotent submit order cancel',
    (tester) async {
      const base = String.fromEnvironment('API_BASE_URL');
      const otp = String.fromEnvironment('LOCAL_OTP_CODE');
      if (base.isEmpty || otp.isEmpty) {
        fail(
          'API_BASE_URL and LOCAL_OTP_CODE dart-defines are required for the emulator journey.',
        );
      }

      final store = FlutterSecureStore(FlutterSecureStore.androidStorage());
      final httpClient = FirstOrderPostAmbiguousClient(http.Client());
      var session = SessionController(store);
      await session.clearAuth();
      var app = await bootstrapMedifind(
        config: AppConfig.parse(base),
        session: session,
        httpClient: httpClient,
        location: DeniedLocationSource(),
      );
      await tester.pumpWidget(app);
      await _settle(tester);

      final suffix = DateTime.now().millisecondsSinceEpoch.toString().padLeft(
        7,
        '0',
      );
      final phone = '0300${suffix.substring(suffix.length - 7)}';

      await tester.enterText(find.byKey(const Key('phone-field')), phone);
      await tester.tap(find.byKey(const Key('request-otp')));
      await _until(tester, find.byKey(const Key('otp-field')));

      await tester.enterText(find.byKey(const Key('otp-field')), otp);
      await tester.tap(find.byKey(const Key('verify-otp')));
      await _until(tester, find.text('Search'));

      await tester.tap(find.text('Account'));
      await _settle(tester);
      await tester.tap(find.byKey(const Key('open-addresses')));
      await _until(tester, find.byKey(const Key('save-address')));

      await tester.enterText(
        find.byKey(const Key('address-street')),
        'Susan Road label only',
      );
      await tester.enterText(
        find.byKey(const Key('address-latitude')),
        '31.450400',
      );
      await tester.enterText(
        find.byKey(const Key('address-longitude')),
        '73.135000',
      );
      await tester.tap(find.byKey(const Key('save-address')));
      await _until(
        tester,
        find.descendant(
          of: find.byType(ListTile),
          matching: find.textContaining('Susan Road label only'),
        ),
      );
      await tester.pageBack();
      await _settle(tester);

      await tester.tap(find.text('Search'));
      await _settle(tester);
      await tester.enterText(
        find.byKey(const Key('search-query')),
        'Catalogpickupotc',
      );
      await tester.tap(find.byKey(const Key('search-submit')));
      await _until(tester, find.text('General use'));

      await tester.tap(find.text('General use'));
      await _until(tester, find.text('This is not dosage advice.'));
      await tester.tap(find.byKey(const Key('close-general-use')));
      await _settle(tester);

      await tester.tap(find.text('Catalogpickupotc · 500 mg · Tablet'));
      await _settle(tester);
      await tester.tap(find.byKey(const Key('find-pharmacies')));
      await _until(tester, find.text('Use pharmacy'));
      await tester.tap(find.text('Use pharmacy'));
      await _until(tester, find.text('Cart updated'));
      await tester.pump(const Duration(seconds: 1));

      await tester.tap(find.text('Cart'));
      await _until(tester, find.byKey(const Key('submit-pickup')));
      await tester.tap(find.byKey(const Key('submit-pickup')));
      await _untilAmbiguousFirstOrderPost(tester, httpClient);

      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump();
      session = SessionController(store);
      app = await bootstrapMedifind(
        config: AppConfig.parse(base),
        session: session,
        httpClient: httpClient,
        location: DeniedLocationSource(),
      );
      await tester.pumpWidget(app);
      await _settle(tester);
      await tester.tap(find.text('Cart'));
      await _untilEither(
        tester,
        find.byKey(const Key('retry-submit')),
        find.byKey(const Key('cancel-order')),
      );
      if (find.byKey(const Key('retry-submit')).evaluate().isNotEmpty) {
        await tester.tap(find.byKey(const Key('retry-submit')));
      }
      await _until(tester, find.byKey(const Key('cancel-order')));

      final detail = tester.element(find.byType(OrderDetailScreen));
      final history = await detail.read<OrderRepository>().list();
      expect(history.orders, hasLength(1));
      expect(find.text(history.orders.single.reference), findsWidgets);

      await tester.tap(find.byKey(const Key('cancel-order')));
      await _until(tester, find.text('This order was cancelled.'));
    },
  );
}

class FirstOrderPostAmbiguousClient extends http.BaseClient {
  FirstOrderPostAmbiguousClient(this._inner);

  final http.Client _inner;
  var discardedCompletedOrderPosts = 0;

  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) async {
    if (request.method == 'POST' &&
        _isCreateOrderPath(request.url.path) &&
        discardedCompletedOrderPosts == 0) {
      final streamed = await _inner.send(request);
      await http.Response.fromStream(streamed);
      discardedCompletedOrderPosts += 1;
      throw TimeoutException('ambiguous-after-complete-response');
    }
    return _inner.send(request);
  }

  static bool _isCreateOrderPath(String path) {
    final normalized = path.endsWith('/')
        ? path.substring(0, path.length - 1)
        : path;
    return normalized == '/api/v1/orders';
  }
}

Future<void> _settle(WidgetTester tester) async {
  await tester.pump(const Duration(milliseconds: 50));
  for (var i = 0; i < 40; i++) {
    await tester.pump(const Duration(milliseconds: 250));
  }
}

Future<void> _untilAmbiguousFirstOrderPost(
  WidgetTester tester,
  FirstOrderPostAmbiguousClient client,
) async {
  final deadline = DateTime.now().add(const Duration(seconds: 45));
  while (DateTime.now().isBefore(deadline)) {
    await tester.pump(const Duration(milliseconds: 250));
    if (client.discardedCompletedOrderPosts == 1 &&
        find.text('The request timed out.').evaluate().isNotEmpty) {
      return;
    }
  }
  fail(
    'Did not observe a completed-then-discarded first order POST followed by a client timeout.',
  );
}

Future<void> _until(WidgetTester tester, Finder finder) async {
  final deadline = DateTime.now().add(const Duration(seconds: 45));
  while (DateTime.now().isBefore(deadline)) {
    await tester.pump(const Duration(milliseconds: 250));
    if (finder.evaluate().isNotEmpty) {
      return;
    }
  }
  fail('Did not find $finder');
}

Future<void> _untilEither(
  WidgetTester tester,
  Finder first,
  Finder second,
) async {
  final deadline = DateTime.now().add(const Duration(seconds: 45));
  while (DateTime.now().isBefore(deadline)) {
    await tester.pump(const Duration(milliseconds: 250));
    if (first.evaluate().isNotEmpty || second.evaluate().isNotEmpty) {
      return;
    }
  }
  fail('Did not find $first or $second');
}
