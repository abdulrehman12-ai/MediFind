import 'package:geolocator/geolocator.dart';

import 'location_source.dart';

class GeolocatorLocationSource implements LocationSource {
  @override
  Future<DeviceCoordinates?> current() async {
    final enabled = await Geolocator.isLocationServiceEnabled();
    if (!enabled) {
      return null;
    }
    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.denied ||
        permission == LocationPermission.deniedForever ||
        permission == LocationPermission.unableToDetermine) {
      return null;
    }
    final position = await Geolocator.getCurrentPosition();
    return DeviceCoordinates(
      latitude: position.latitude,
      longitude: position.longitude,
      source: 'device_gps',
    );
  }
}
