import 'package:flutter/material.dart';

import 'bootstrap.dart';
import 'config/app_config.dart';
import 'session.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final config = AppConfig.fromEnvironment();
  final session = SessionController(
    FlutterSecureStore(FlutterSecureStore.androidStorage()),
  );
  final app = await bootstrapMedifind(config: config, session: session);
  runApp(app);
}
