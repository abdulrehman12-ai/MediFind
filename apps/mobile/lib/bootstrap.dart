import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:provider/provider.dart';

import 'api/api_client.dart';
import 'config/app_config.dart';
import 'location/geolocator_source.dart';
import 'location/location_source.dart';
import 'repositories.dart';
import 'session.dart';
import 'ui/app.dart';

Future<Widget> bootstrapMedifind({
  required AppConfig config,
  required SessionController session,
  http.Client? httpClient,
  LocationSource? location,
}) async {
  await session.load();
  final api = ApiClient(
    baseUrl: config.apiBaseUrl,
    httpClient: httpClient ?? http.Client(),
    readToken: () async => session.accessToken,
    onUnauthenticated: () => session.clearAuth(keepPending: true),
  );
  return MultiProvider(
    providers: [
      ChangeNotifierProvider.value(value: session),
      ChangeNotifierProvider(create: (_) => SignInDraft()),
      ChangeNotifierProvider(create: (_) => CatalogSelection()),
      Provider<LocationSource>(
        create: (_) => location ?? GeolocatorLocationSource(),
      ),
      Provider(create: (_) => AuthRepository(api)),
      Provider(create: (_) => ProfileRepository(api)),
      Provider(create: (_) => DiscoveryRepository(api)),
      Provider(create: (_) => CartRepository(api)),
      Provider(create: (_) => OrderRepository(api)),
    ],
    child: MedifindApp(session: session),
  );
}
