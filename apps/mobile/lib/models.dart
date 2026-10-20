import 'format.dart';

class Customer {
  Customer({
    required this.id,
    required this.phoneMasked,
    required this.fullName,
    required this.email,
    required this.role,
    required this.status,
    this.phoneVerifiedAt,
  });

  final String id;
  final String phoneMasked;
  final String? fullName;
  final String? email;
  final String role;
  final String status;
  final DateTime? phoneVerifiedAt;

  factory Customer.fromJson(Map<String, dynamic> json) {
    return Customer(
      id: json['id'] as String,
      phoneMasked: json['phone_masked'] as String,
      fullName: json['full_name'] as String?,
      email: json['email'] as String?,
      role: json['role'] as String,
      status: json['status'] as String,
      phoneVerifiedAt: parseInstantOrNull(json['phone_verified_at']),
    );
  }
}

class SavedAddress {
  SavedAddress({
    required this.id,
    required this.label,
    required this.addressText,
    required this.city,
    required this.latitude,
    required this.longitude,
    required this.isDefault,
  });

  final String id;
  final String label;
  final String addressText;
  final String city;
  final String latitude;
  final String longitude;
  final bool isDefault;

  factory SavedAddress.fromJson(Map<String, dynamic> json) {
    return SavedAddress(
      id: json['id'] as String,
      label: json['label'] as String,
      addressText: json['address_text'] as String,
      city: json['city'] as String,
      latitude: json['latitude'].toString(),
      longitude: json['longitude'].toString(),
      isDefault: json['is_default'] as bool,
    );
  }
}

class MedicineVariant {
  MedicineVariant({
    required this.id,
    required this.genericName,
    required this.brandName,
    required this.strength,
    required this.dosageForm,
    required this.manufacturer,
    required this.prescriptionRequired,
  });

  final String id;
  final String genericName;
  final String? brandName;
  final String? strength;
  final String dosageForm;
  final String? manufacturer;
  final bool prescriptionRequired;

  factory MedicineVariant.fromJson(Map<String, dynamic> json) {
    return MedicineVariant(
      id: json['id'] as String,
      genericName: json['generic_name'] as String,
      brandName: json['brand_name'] as String?,
      strength: json['strength'] as String?,
      dosageForm: json['dosage_form'] as String,
      manufacturer: json['manufacturer'] as String?,
      prescriptionRequired: json['prescription_required'] as bool,
    );
  }

  String get title =>
      [genericName, strength, dosageForm].whereType<String>().join(' · ');
}

class PharmacyAvailability {
  PharmacyAvailability({
    required this.id,
    required this.name,
    required this.address,
    required this.distanceMeters,
    required this.distanceMethod,
    required this.medicines,
  });

  final String id;
  final String name;
  final String address;
  final int distanceMeters;
  final String distanceMethod;
  final List<MedicineVariant> medicines;

  factory PharmacyAvailability.fromJson(Map<String, dynamic> json) {
    final medicines = <MedicineVariant>[];
    for (final row in json['medicines'] as List<dynamic>) {
      medicines.add(MedicineVariant.fromJson(row as Map<String, dynamic>));
    }
    return PharmacyAvailability(
      id: json['id'] as String,
      name: json['name'] as String,
      address: json['address'] as String,
      distanceMeters: json['distance_meters'] as int,
      distanceMethod: json['distance_method'] as String,
      medicines: medicines,
    );
  }
}

class CartItem {
  CartItem({
    required this.medicineId,
    required this.quantity,
    required this.eligible,
    required this.priceIsEstimate,
    this.genericName,
    this.unitPricePaisa,
  });

  final String medicineId;
  final int quantity;
  final bool eligible;
  final bool priceIsEstimate;
  final String? genericName;
  final int? unitPricePaisa;

  factory CartItem.fromJson(Map<String, dynamic> json) {
    return CartItem(
      medicineId: json['medicine_id'] as String,
      quantity: json['quantity'] as int,
      eligible: json['eligible'] as bool,
      priceIsEstimate: json['price_is_estimate'] as bool,
      genericName: json['generic_name'] as String?,
      unitPricePaisa: json['unit_price_paisa'] as int?,
    );
  }
}

class Cart {
  Cart({
    required this.version,
    required this.pharmacyId,
    required this.requestMode,
    required this.amountsAreEstimates,
    required this.items,
    this.customerNote,
  });

  final int version;
  final String pharmacyId;
  final String requestMode;
  final bool amountsAreEstimates;
  final String? customerNote;
  final List<CartItem> items;

  factory Cart.fromJson(Map<String, dynamic> json) {
    return Cart(
      version: json['version'] as int,
      pharmacyId: json['pharmacy_id'] as String,
      requestMode: json['request_mode'] as String,
      amountsAreEstimates: json['amounts_are_estimates'] as bool,
      customerNote: json['customer_note'] as String?,
      items: [
        for (final row in json['items'] as List<dynamic>)
          CartItem.fromJson(row as Map<String, dynamic>),
      ],
    );
  }
}

class PickupWindow {
  PickupWindow({
    required this.startsAt,
    required this.endsAt,
    required this.collectUntil,
    required this.graceSeconds,
  });

  final DateTime startsAt;
  final DateTime endsAt;
  final DateTime collectUntil;
  final int graceSeconds;

  factory PickupWindow.fromJson(Map<String, dynamic> json) {
    return PickupWindow(
      startsAt: parseInstant(json['starts_at'] as String),
      endsAt: parseInstant(json['ends_at'] as String),
      collectUntil: parseInstant(json['collect_until'] as String),
      graceSeconds: json['grace_seconds'] as int,
    );
  }
}

class OrderQuote {
  OrderQuote({
    required this.id,
    required this.version,
    required this.state,
    required this.totalPaisa,
    required this.expiresAt,
    required this.accepted,
  });

  final String id;
  final int version;
  final String state;
  final int totalPaisa;
  final DateTime expiresAt;
  final bool accepted;

  factory OrderQuote.fromJson(Map<String, dynamic> json) {
    return OrderQuote(
      id: json['id'] as String,
      version: json['version'] as int,
      state: json['state'] as String,
      totalPaisa: json['total_paisa'] as int,
      expiresAt: parseInstant(json['expires_at'] as String),
      accepted: json['accepted'] as bool,
    );
  }
}

class TimelineEvent {
  TimelineEvent({
    required this.status,
    required this.event,
    required this.at,
    required this.actor,
    this.cause,
    this.reason,
  });

  final String status;
  final String event;
  final DateTime at;
  final String actor;
  final String? cause;
  final String? reason;

  factory TimelineEvent.fromJson(Map<String, dynamic> json) {
    return TimelineEvent(
      status: json['status'] as String,
      event: json['event'] as String,
      at: parseInstant(json['at'] as String),
      actor: json['actor'] as String,
      cause: json['cause'] as String?,
      reason: json['reason'] as String?,
    );
  }
}

class CustomerOrder {
  CustomerOrder({
    required this.id,
    required this.reference,
    required this.pharmacyId,
    required this.status,
    required this.statusText,
    required this.requestMode,
    required this.prescriptionUsable,
    required this.paymentMethod,
    required this.paymentStatus,
    required this.amountsAreEstimates,
    required this.notices,
    required this.timeline,
    required this.createdAt,
    this.prescriptionId,
    this.estimateTotalPaisa,
    this.confirmedTotalPaisa,
    this.pickupWindow,
    this.cashCollectedPaisa,
    this.quote,
    this.customerNote,
  });

  final String id;
  final String reference;
  final String pharmacyId;
  final String status;
  final String statusText;
  final String requestMode;
  final String? prescriptionId;
  final bool prescriptionUsable;
  final String paymentMethod;
  final String paymentStatus;
  final bool amountsAreEstimates;
  final int? estimateTotalPaisa;
  final int? confirmedTotalPaisa;
  final PickupWindow? pickupWindow;
  final int? cashCollectedPaisa;
  final List<String> notices;
  final OrderQuote? quote;
  final List<TimelineEvent> timeline;
  final DateTime createdAt;
  final String? customerNote;

  factory CustomerOrder.fromJson(Map<String, dynamic> json) {
    PickupWindow? window;
    final rawWindow = json['pickup_window'];
    if (rawWindow is Map<String, dynamic>) {
      window = PickupWindow.fromJson(rawWindow);
    }
    OrderQuote? quote;
    final rawQuote = json['quote'];
    if (rawQuote is Map<String, dynamic>) {
      quote = OrderQuote.fromJson(rawQuote);
    }
    return CustomerOrder(
      id: json['id'] as String,
      reference: json['reference'] as String,
      pharmacyId: json['pharmacy_id'] as String,
      status: json['status'] as String,
      statusText: json['status_text'] as String,
      requestMode: json['request_mode'] as String,
      prescriptionId: json['prescription_id'] as String?,
      prescriptionUsable: json['prescription_usable'] as bool,
      paymentMethod: json['payment_method'] as String,
      paymentStatus: json['payment_status'] as String,
      amountsAreEstimates: json['amounts_are_estimates'] as bool,
      estimateTotalPaisa: json['estimate_total_paisa'] as int?,
      confirmedTotalPaisa: json['confirmed_total_paisa'] as int?,
      pickupWindow: window,
      cashCollectedPaisa: json['cash_collected_paisa'] as int?,
      notices: [
        for (final row in json['notices'] as List<dynamic>) row as String,
      ],
      quote: quote,
      timeline: [
        for (final row in json['timeline'] as List<dynamic>)
          TimelineEvent.fromJson(row as Map<String, dynamic>),
      ],
      createdAt: parseInstant(json['created_at'] as String),
      customerNote: json['customer_note'] as String?,
    );
  }

  bool get canCancel =>
      status == 'pending_pharmacy_review' || status == 'confirmed';
}

class OrderHistoryPage {
  OrderHistoryPage({
    required this.orders,
    required this.hasMore,
    this.nextCursor,
  });

  final List<CustomerOrder> orders;
  final bool hasMore;
  final String? nextCursor;

  factory OrderHistoryPage.fromJson(Map<String, dynamic> json) {
    final meta = json['meta'] as Map<String, dynamic>? ?? const {};
    return OrderHistoryPage(
      orders: [
        for (final row in json['data'] as List<dynamic>)
          CustomerOrder.fromJson(row as Map<String, dynamic>),
      ],
      hasMore: meta['has_more'] as bool? ?? false,
      nextCursor: meta['next_cursor'] as String?,
    );
  }
}
