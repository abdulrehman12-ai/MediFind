import 'dart:convert';
import 'dart:math';

import 'package:clock/clock.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'models.dart';

abstract class SecureStore {
  Future<void> write(String key, String value);
  Future<String?> read(String key);
  Future<void> delete(String key);
  Future<void> deleteAll();
}

class FlutterSecureStore implements SecureStore {
  FlutterSecureStore(this._storage);

  final FlutterSecureStorage _storage;

  static FlutterSecureStorage androidStorage() {
    return const FlutterSecureStorage(
      aOptions: AndroidOptions(
        encryptedSharedPreferences: true,
        resetOnError: true,
      ),
    );
  }

  @override
  Future<void> write(String key, String value) =>
      _storage.write(key: key, value: value);

  @override
  Future<String?> read(String key) => _storage.read(key: key);

  @override
  Future<void> delete(String key) => _storage.delete(key: key);

  @override
  Future<void> deleteAll() => _storage.deleteAll();
}

class MemorySecureStore implements SecureStore {
  final Map<String, String> _values = {};

  @override
  Future<void> write(String key, String value) async => _values[key] = value;

  @override
  Future<String?> read(String key) async => _values[key];

  @override
  Future<void> delete(String key) async => _values.remove(key);

  @override
  Future<void> deleteAll() async => _values.clear();
}

final RegExp _uuidPattern = RegExp(
  r'^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$',
);

final RegExp _idempotencyPattern = RegExp(r'^[A-Za-z0-9_-]{16,128}$');

bool isMedifindUuid(String value) => _uuidPattern.hasMatch(value);

class PendingSubmit {
  PendingSubmit({
    required this.idempotencyKey,
    required this.cartVersion,
    required this.pharmacyId,
    required this.customerId,
  });

  final String idempotencyKey;
  final int cartVersion;
  final String pharmacyId;
  final String customerId;

  Map<String, dynamic> toJson() => {
    'idempotency_key': idempotencyKey,
    'cart_version': cartVersion,
    'pharmacy_id': pharmacyId,
    'customer_id': customerId,
  };

  static PendingSubmit? tryParse(Object? raw) {
    if (raw is! Map<String, dynamic>) {
      return null;
    }
    final key = raw['idempotency_key'];
    final version = raw['cart_version'];
    final pharmacyId = raw['pharmacy_id'];
    final customerId = raw['customer_id'];
    if (key is! String || !_idempotencyPattern.hasMatch(key)) {
      return null;
    }
    if (version is! int) {
      return null;
    }
    if (pharmacyId is! String || !isMedifindUuid(pharmacyId)) {
      return null;
    }
    if (customerId is! String || !isMedifindUuid(customerId)) {
      return null;
    }
    return PendingSubmit(
      idempotencyKey: key,
      cartVersion: version,
      pharmacyId: pharmacyId,
      customerId: customerId,
    );
  }

  static String generateKey([Random? random]) {
    const alphabet =
        'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';
    final rng = random ?? Random.secure();
    return List.generate(
      32,
      (_) => alphabet[rng.nextInt(alphabet.length)],
    ).join();
  }
}

class SessionController extends ChangeNotifier {
  SessionController(this._store);

  static const tokenKey = 'access_token';
  static const expiryKey = 'expires_at';
  static const customerKey = 'customer_json';
  static const pendingKey = 'pending_submit';

  final SecureStore _store;

  String? accessToken;
  DateTime? expiresAt;
  Customer? customer;
  PendingSubmit? pendingSubmit;
  bool loaded = false;

  bool get isSignedIn {
    final token = accessToken;
    final expiry = expiresAt;
    final signedIn = customer;
    if (token == null || token.isEmpty) {
      return false;
    }
    if (expiry == null || !_isFutureUtc(expiry)) {
      return false;
    }
    if (signedIn == null || !_customerIsValid(signedIn)) {
      return false;
    }
    return true;
  }

  bool get isExpired {
    final expiry = expiresAt;
    if (expiry == null) {
      return true;
    }
    return !_isFutureUtc(expiry);
  }

  bool canRetryPendingFor(String? customerId) {
    final pending = pendingSubmit;
    if (pending == null || customerId == null) {
      return false;
    }
    return pending.customerId == customerId;
  }

  Future<void> load() async {
    accessToken = null;
    expiresAt = null;
    customer = null;
    pendingSubmit = null;
    try {
      final token = await _read(tokenKey);
      final rawExpiry = await _read(expiryKey);
      final rawCustomer = await _read(customerKey);
      final rawPending = await _read(pendingKey);

      final expiry = _parseUtcExpiry(rawExpiry);
      final signedIn = _parseCustomer(rawCustomer);
      final pending = _parsePending(rawPending);

      if (rawPending != null && pending == null) {
        await _delete(pendingKey);
      } else {
        pendingSubmit = pending;
      }

      final authOk =
          token != null &&
          token.isNotEmpty &&
          expiry != null &&
          signedIn != null &&
          _isFutureUtc(expiry);
      if (authOk) {
        accessToken = token;
        expiresAt = expiry;
        customer = signedIn;
        await _dropPendingIfNotOwnedBy(signedIn.id);
      } else {
        await _delete(tokenKey);
        await _delete(expiryKey);
        await _delete(customerKey);
        if (pendingSubmit != null &&
            signedIn != null &&
            pendingSubmit!.customerId != signedIn.id) {
          await clearPending();
        }
      }
    } catch (_) {
      accessToken = null;
      expiresAt = null;
      customer = null;
      await _delete(tokenKey);
      await _delete(expiryKey);
      await _delete(customerKey);
      await _delete(pendingKey);
      pendingSubmit = null;
    } finally {
      loaded = true;
      notifyListeners();
    }
  }

  Future<void> saveAuth({
    required String token,
    required DateTime expires,
    required Customer signedIn,
  }) async {
    final expiry = expires.toUtc();
    if (token.isEmpty || !_isFutureUtc(expiry) || !_customerIsValid(signedIn)) {
      await clearAuth(keepPending: true);
      return;
    }
    accessToken = token;
    expiresAt = expiry;
    customer = signedIn;
    await _write(tokenKey, token);
    await _write(expiryKey, expiry.toIso8601String());
    await _write(
      customerKey,
      jsonEncode({
        'id': signedIn.id,
        'phone_masked': signedIn.phoneMasked,
        'full_name': signedIn.fullName,
        'email': signedIn.email,
        'role': signedIn.role,
        'status': signedIn.status,
        'phone_verified_at': signedIn.phoneVerifiedAt?.toIso8601String(),
      }),
    );
    await _dropPendingIfNotOwnedBy(signedIn.id);
    notifyListeners();
  }

  Future<void> savePending(PendingSubmit pending) async {
    if (!isSignedIn || customer == null || pending.customerId != customer!.id) {
      return;
    }
    pendingSubmit = pending;
    await _write(pendingKey, jsonEncode(pending.toJson()));
    notifyListeners();
  }

  Future<void> clearPending() async {
    pendingSubmit = null;
    await _delete(pendingKey);
    notifyListeners();
  }

  Future<void> clearAuth({bool keepPending = false}) async {
    accessToken = null;
    expiresAt = null;
    customer = null;
    if (keepPending) {
      await _delete(tokenKey);
      await _delete(expiryKey);
      await _delete(customerKey);
      final pending = pendingSubmit;
      if (pending == null || !isMedifindUuid(pending.customerId)) {
        await clearPending();
      }
    } else {
      pendingSubmit = null;
      try {
        await _store.deleteAll();
      } catch (_) {
        await _delete(tokenKey);
        await _delete(expiryKey);
        await _delete(customerKey);
        await _delete(pendingKey);
      }
    }
    notifyListeners();
  }

  Future<void> _dropPendingIfNotOwnedBy(String customerId) async {
    final pending = pendingSubmit;
    if (pending == null) {
      return;
    }
    if (pending.customerId != customerId) {
      await clearPending();
    }
  }

  Future<String?> _read(String key) async {
    try {
      return await _store.read(key);
    } catch (_) {
      return null;
    }
  }

  Future<void> _write(String key, String value) async {
    try {
      await _store.write(key, value);
    } catch (_) {
      // Fail closed in memory even if persistence fails.
    }
  }

  Future<void> _delete(String key) async {
    try {
      await _store.delete(key);
    } catch (_) {}
  }

  static bool _isFutureUtc(DateTime expiry) {
    return expiry.isUtc && clock.now().toUtc().isBefore(expiry);
  }

  static DateTime? _parseUtcExpiry(String? raw) {
    if (raw == null || raw.isEmpty) {
      return null;
    }
    try {
      final parsed = DateTime.parse(raw);
      if (!parsed.isUtc) {
        return null;
      }
      return parsed.toUtc();
    } catch (_) {
      return null;
    }
  }

  static Customer? _parseCustomer(String? raw) {
    if (raw == null || raw.isEmpty) {
      return null;
    }
    try {
      final decoded = jsonDecode(raw);
      if (decoded is! Map<String, dynamic>) {
        return null;
      }
      final customer = Customer.fromJson(decoded);
      if (!_customerIsValid(customer)) {
        return null;
      }
      return customer;
    } catch (_) {
      return null;
    }
  }

  static PendingSubmit? _parsePending(String? raw) {
    if (raw == null || raw.isEmpty) {
      return null;
    }
    try {
      return PendingSubmit.tryParse(jsonDecode(raw));
    } catch (_) {
      return null;
    }
  }

  static bool _customerIsValid(Customer customer) {
    return isMedifindUuid(customer.id) &&
        customer.phoneMasked.isNotEmpty &&
        customer.role == 'customer' &&
        customer.status.isNotEmpty;
  }
}

class SignInDraft extends ChangeNotifier {
  String phone = '';
  String challengeId = '';
}
