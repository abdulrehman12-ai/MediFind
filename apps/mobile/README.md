# MediFind customer app (Phase 7.1)

Android-first Flutter client. iOS is not verified.

## Tool versions

- Flutter 3.47.x (3.47.6 used for local install)
- Dart SDK bundled with that Flutter release
- JDK 17 (Eclipse Temurin)
- Android SDK command-line tools (licenses accepted by the developer)

## Configuration

Never put a production API URL in source. Pass:

```
--dart-define=API_BASE_URL=http://10.0.2.2:18080
```

for the isolated HTTP test (emulator loopback to the host). Host loopback `127.0.0.1` inside the emulator is not the Windows host.

Release builds refuse non-HTTPS URLs and refuse localhost / `10.0.2.2`.

The isolated OTP code is passed only to the integration test runner as `--dart-define=LOCAL_OTP_CODE=...` from gitignored secrets. It must not appear in logs.

## Tests

```
flutter analyze
dart format --output=none --set-exit-if-changed .
flutter test
```
