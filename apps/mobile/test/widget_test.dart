import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:medifind/bootstrap.dart';
import 'package:medifind/config/app_config.dart';
import 'package:medifind/location/location_source.dart';
import 'package:medifind/models.dart';
import 'package:medifind/session.dart';
import 'package:medifind/ui/app.dart';
import 'package:provider/provider.dart';

void main() {
  testWidgets('sign-in screen exposes accessible phone field and errors', (
    tester,
  ) async {
    final session = SessionController(MemorySecureStore())..loaded = true;
    await tester.pumpWidget(
      MultiProvider(
        providers: [
          ChangeNotifierProvider.value(value: session),
          ChangeNotifierProvider(create: (_) => SignInDraft()),
        ],
        child: MedifindApp(session: session),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Mobile number'), findsOneWidget);
    expect(find.text('Request code'), findsOneWidget);
    expect(find.bySemanticsLabel('Mobile number'), findsWidgets);
    expect(find.byKey(const Key('phone-field')), findsOneWidget);
    expect(find.byKey(const Key('request-otp')), findsOneWidget);
  });

  testWidgets('401 on cart returns to sign-in and keeps pending submit', (
    tester,
  ) async {
    final session = SessionController(MemorySecureStore());
    await session.saveAuth(
      token: 'expired-session',
      expires: DateTime.utc(2027, 1, 1),
      signedIn: Customer(
        id: '11111111-1111-1111-1111-111111111111',
        phoneMasked: '+92300***4567',
        fullName: null,
        email: null,
        role: 'customer',
        status: 'active',
      ),
    );
    await session.savePending(
      PendingSubmit(
        idempotencyKey: 'KeepKey_widget_1234567',
        cartVersion: 3,
        pharmacyId: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        customerId: '11111111-1111-1111-1111-111111111111',
      ),
    );
    final app = await bootstrapMedifind(
      config: AppConfig.parse('http://10.0.2.2:18080'),
      session: session,
      location: DeniedLocationSource(),
      httpClient: MockClient((request) async {
        return http.Response(
          jsonEncode({
            'error': {'code': 'unauthenticated', 'message': 'no'},
          }),
          401,
          headers: {'content-type': 'application/json'},
        );
      }),
    );
    await tester.pumpWidget(app);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Cart'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pumpAndSettle();
    expect(find.text('Request code'), findsOneWidget);
    expect(session.isSignedIn, isFalse);
    expect(session.pendingSubmit?.idempotencyKey, 'KeepKey_widget_1234567');
  });
}
