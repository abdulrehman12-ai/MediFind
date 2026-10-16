class DeviceCoordinates {
  const DeviceCoordinates({
    required this.latitude,
    required this.longitude,
    required this.source,
  });

  final double latitude;
  final double longitude;
  final String source;
}

abstract class LocationSource {
  Future<DeviceCoordinates?> current();
}

class DeniedLocationSource implements LocationSource {
  @override
  Future<DeviceCoordinates?> current() async => null;
}

class FixedLocationSource implements LocationSource {
  FixedLocationSource(this.value);
  final DeviceCoordinates value;
  @override
  Future<DeviceCoordinates?> current() async => value;
}
