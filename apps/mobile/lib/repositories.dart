import 'dart:convert';

import 'package:http/http.dart' as http;

import 'api/api_client.dart';
import 'format.dart';
import 'models.dart';

class AuthRepository {
  AuthRepository(this._api);
  final ApiClient _api;

  Future<({String challengeId, int expiresIn, int retryAfter})> requestOtp(
    String phone,
  ) async {
    final payload = await _api.sendJson(
      method: 'POST',
      path: '/api/v1/auth/otp/request',
      body: {'phone_number': phone},
      auth: false,
      expectedStatus: 202,
    );
    final data = payload?['data'] as Map<String, dynamic>;
    return (
      challengeId: data['challenge_id'] as String,
      expiresIn: data['expires_in'] as int,
      retryAfter: data['retry_after'] as int,
    );
  }

  Future<({String token, DateTime expiresAt, Customer customer})> verifyOtp({
    required String phone,
    required String challengeId,
    required String otp,
  }) async {
    final payload = await _api.sendJson(
      method: 'POST',
      path: '/api/v1/auth/otp/verify',
      body: {'phone_number': phone, 'challenge_id': challengeId, 'otp': otp},
      auth: false,
    );
    final data = payload?['data'] as Map<String, dynamic>;
    return (
      token: data['access_token'] as String,
      expiresAt: parseInstant(data['expires_at'] as String),
      customer: Customer.fromJson(data['customer'] as Map<String, dynamic>),
    );
  }

  Future<void> logout() => _api.sendJson(
    method: 'POST',
    path: '/api/v1/auth/logout',
    expectedStatus: 204,
  );

  Future<void> logoutAll() => _api.sendJson(
    method: 'POST',
    path: '/api/v1/auth/logout-all',
    expectedStatus: 204,
  );
}

class ProfileRepository {
  ProfileRepository(this._api);
  final ApiClient _api;

  Future<Customer> me() async {
    final payload = await _api.sendJson(method: 'GET', path: '/api/v1/me');
    return Customer.fromJson(payload?['data'] as Map<String, dynamic>);
  }

  Future<Customer> update({String? fullName, String? email}) async {
    final payload = await _api.sendJson(
      method: 'PATCH',
      path: '/api/v1/me',
      body: {'full_name': ?fullName, 'email': ?email},
    );
    return Customer.fromJson(payload?['data'] as Map<String, dynamic>);
  }

  Future<List<SavedAddress>> addresses() async {
    final payload = await _api.sendJson(
      method: 'GET',
      path: '/api/v1/me/addresses',
    );
    final data = payload?['data'];
    final rows = data is List<dynamic>
        ? data
        : (data as Map<String, dynamic>)['addresses'] as List<dynamic>;
    return [
      for (final row in rows)
        SavedAddress.fromJson(row as Map<String, dynamic>),
    ];
  }

  Future<SavedAddress> createAddress(Map<String, dynamic> body) async {
    final payload = await _api.sendJson(
      method: 'POST',
      path: '/api/v1/me/addresses',
      body: body,
    );
    return SavedAddress.fromJson(payload?['data'] as Map<String, dynamic>);
  }
}

class DiscoveryRepository {
  DiscoveryRepository(this._api);
  final ApiClient _api;

  Future<List<MedicineVariant>> search(String query) async {
    final payload = await _api.sendJson(
      method: 'GET',
      path: '/api/v1/medicines',
      query: {'q': query},
    );
    return [
      for (final row in payload?['data'] as List<dynamic>)
        MedicineVariant.fromJson(row as Map<String, dynamic>),
    ];
  }

  Future<({bool available, String? text, String disclaimer})> generalUse(
    String medicineId,
  ) async {
    final payload = await _api.sendJson(
      method: 'GET',
      path: '/api/v1/medicines/$medicineId/general-use',
    );
    final data = payload?['data'] as Map<String, dynamic>;
    return (
      available: data['available'] as bool,
      text: data['general_use'] as String?,
      disclaimer: data['safety_disclaimer'] as String,
    );
  }

  Future<({List<PharmacyAvailability> pharmacies, String confirmation})>
  availability({
    required List<String> medicineIds,
    String? addressId,
    String? latitude,
    String? longitude,
  }) async {
    final query = <String, Object>{'medicine_ids[]': medicineIds};
    if (addressId != null) {
      query['address_id'] = addressId;
    }
    if (latitude != null && longitude != null) {
      query['latitude'] = latitude;
      query['longitude'] = longitude;
    }
    final payload = await _api.sendJson(
      method: 'GET',
      path: '/api/v1/availability',
      query: query,
    );
    final meta = payload?['meta'] as Map<String, dynamic>;
    return (
      pharmacies: [
        for (final row in payload?['data'] as List<dynamic>)
          PharmacyAvailability.fromJson(row as Map<String, dynamic>),
      ],
      confirmation: meta['confirmation'] as String,
    );
  }
}

class CartRepository {
  CartRepository(this._api);
  final ApiClient _api;

  Future<Cart?> get() async {
    final payload = await _api.sendJson(method: 'GET', path: '/api/v1/cart');
    final data = payload?['data'];
    if (data == null) {
      return null;
    }
    return Cart.fromJson(data as Map<String, dynamic>);
  }

  Future<Cart> replace(Map<String, dynamic> body) async {
    final payload = await _api.sendJson(
      method: 'PUT',
      path: '/api/v1/cart',
      body: body,
    );
    return Cart.fromJson(payload?['data'] as Map<String, dynamic>);
  }
}

class OrderRepository {
  OrderRepository(this._api);
  final ApiClient _api;

  Future<({CustomerOrder order, int statusCode})> submit({
    required int cartVersion,
    required String idempotencyKey,
  }) async {
    final response = await _api.send(
      method: 'POST',
      path: '/api/v1/orders',
      body: jsonEncode({'cart_version': cartVersion}),
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'Idempotency-Key': idempotencyKey,
      },
    );
    if (response.statusCode >= 400) {
      _api.throwFrom(response);
    }
    return (order: _orderFrom(response), statusCode: response.statusCode);
  }

  Future<OrderHistoryPage> list({String? cursor}) async {
    final query = <String, Object>{};
    if (cursor != null && cursor.isNotEmpty) {
      query['cursor'] = cursor;
    }
    final payload = await _api.sendJson(
      method: 'GET',
      path: '/api/v1/orders',
      query: query.isEmpty ? null : query,
    );
    return OrderHistoryPage.fromJson(payload ?? const {'data': <dynamic>[]});
  }

  Future<CustomerOrder> show(String id) async {
    final payload = await _api.sendJson(
      method: 'GET',
      path: '/api/v1/orders/$id',
    );
    return CustomerOrder.fromJson(payload?['data'] as Map<String, dynamic>);
  }

  Future<CustomerOrder> cancel(String id, {String? reason}) async {
    final payload = await _api.sendJson(
      method: 'POST',
      path: '/api/v1/orders/$id/cancel',
      body: {'reason': ?reason},
    );
    return CustomerOrder.fromJson(payload?['data'] as Map<String, dynamic>);
  }

  CustomerOrder _orderFrom(http.Response response) {
    final decoded = jsonDecode(response.body) as Map<String, dynamic>;
    return CustomerOrder.fromJson(decoded['data'] as Map<String, dynamic>);
  }
}
