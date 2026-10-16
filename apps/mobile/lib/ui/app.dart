import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../api/api_problem.dart';
import '../format.dart';
import '../location/location_source.dart';
import '../models.dart';
import '../repositories.dart';
import '../session.dart';

class MedifindApp extends StatelessWidget {
  const MedifindApp({super.key, required this.session});

  final SessionController session;

  @override
  Widget build(BuildContext context) {
    final router = GoRouter(
      refreshListenable: session,
      initialLocation: '/sign-in',
      redirect: (context, state) {
        if (!session.loaded) {
          return null;
        }
        final signingIn =
            state.matchedLocation == '/sign-in' ||
            state.matchedLocation == '/otp';
        if (!session.isSignedIn && !signingIn) {
          return '/sign-in';
        }
        if (session.isSignedIn && signingIn) {
          return '/home';
        }
        return null;
      },
      routes: [
        GoRoute(
          path: '/sign-in',
          builder: (context, state) => const PhoneScreen(),
        ),
        GoRoute(path: '/otp', builder: (context, state) => const OtpScreen()),
        GoRoute(path: '/home', builder: (context, state) => const HomeScreen()),
        GoRoute(
          path: '/addresses',
          builder: (context, state) => const AddressScreen(),
        ),
        GoRoute(
          path: '/orders/:id',
          builder: (context, state) =>
              OrderDetailScreen(orderId: state.pathParameters['id']!),
        ),
      ],
    );
    return MaterialApp.router(
      title: 'MediFind',
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xFF0B4F6C)),
        useMaterial3: true,
      ),
      routerConfig: router,
    );
  }
}

String problemLabel(Object error) {
  if (error is ApiProblem) {
    return error.userMessage;
  }
  if (error is ApiTransportException) {
    return error.message;
  }
  return 'The request failed.';
}

class PhoneScreen extends StatefulWidget {
  const PhoneScreen({super.key});
  @override
  State<PhoneScreen> createState() => _PhoneScreenState();
}

class _PhoneScreenState extends State<PhoneScreen> {
  final controller = TextEditingController();
  String? error;
  bool loading = false;

  @override
  void dispose() {
    controller.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final phone = controller.text.trim();
      final result = await context.read<AuthRepository>().requestOtp(phone);
      if (!mounted) {
        return;
      }
      final draft = context.read<SignInDraft>();
      draft.phone = phone;
      draft.challengeId = result.challengeId;
      context.go('/otp');
    } catch (err) {
      setState(() => error = problemLabel(err));
    } finally {
      if (mounted) {
        setState(() => loading = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('MediFind')),
      body: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text(
              'Enter a Pakistani mobile number to request a one-time code.',
            ),
            const SizedBox(height: 12),
            Semantics(
              label: 'Mobile number',
              textField: true,
              child: TextField(
                key: const Key('phone-field'),
                controller: controller,
                keyboardType: TextInputType.phone,
                autofillHints: const [AutofillHints.telephoneNumber],
                decoration: const InputDecoration(
                  labelText: 'Mobile number',
                  hintText: '03XXXXXXXXX',
                ),
              ),
            ),
            if (error != null) ...[
              const SizedBox(height: 8),
              Text(
                error!,
                semanticsLabel: 'Error: $error',
                style: const TextStyle(color: Colors.red),
              ),
            ],
            const SizedBox(height: 16),
            FilledButton(
              key: const Key('request-otp'),
              onPressed: loading ? null : _submit,
              child: Text(loading ? 'Requesting code' : 'Request code'),
            ),
          ],
        ),
      ),
    );
  }
}

class OtpScreen extends StatefulWidget {
  const OtpScreen({super.key});
  @override
  State<OtpScreen> createState() => _OtpScreenState();
}

class _OtpScreenState extends State<OtpScreen> {
  final controller = TextEditingController();
  String? error;
  bool loading = false;

  @override
  void dispose() {
    controller.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final draft = context.read<SignInDraft>();
    final phone = draft.phone;
    final challengeId = draft.challengeId;
    final auth = context.read<AuthRepository>();
    final session = context.read<SessionController>();
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final result = await auth.verifyOtp(
        phone: phone,
        challengeId: challengeId,
        otp: controller.text.trim(),
      );
      await session.saveAuth(
        token: result.token,
        expires: result.expiresAt,
        signedIn: result.customer,
      );
    } catch (err) {
      setState(() => error = problemLabel(err));
    } finally {
      if (mounted) {
        setState(() => loading = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Enter code')),
      body: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text(
              'Enter the six-digit code. MediFind never displays the code from the server.',
            ),
            Semantics(
              label: 'One-time code',
              textField: true,
              child: TextField(
                key: const Key('otp-field'),
                controller: controller,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(labelText: 'One-time code'),
                obscureText: true,
                enableSuggestions: false,
              ),
            ),
            if (error != null)
              Text(
                error!,
                semanticsLabel: 'Error: $error',
                style: const TextStyle(color: Colors.red),
              ),
            FilledButton(
              key: const Key('verify-otp'),
              onPressed: loading ? null : _submit,
              child: Text(loading ? 'Verifying' : 'Verify'),
            ),
          ],
        ),
      ),
    );
  }
}

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});
  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  int tab = 0;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('MediFind')),
      body: const [SearchTab(), CartTab(), OrdersTab(), AccountTab()][tab],
      bottomNavigationBar: NavigationBar(
        selectedIndex: tab,
        onDestinationSelected: (value) => setState(() => tab = value),
        destinations: const [
          NavigationDestination(
            key: Key('nav-search'),
            icon: Icon(Icons.search),
            label: 'Search',
          ),
          NavigationDestination(
            key: Key('nav-cart'),
            icon: Icon(Icons.shopping_bag),
            label: 'Cart',
          ),
          NavigationDestination(
            key: Key('nav-orders'),
            icon: Icon(Icons.receipt_long),
            label: 'Orders',
          ),
          NavigationDestination(
            key: Key('nav-account'),
            icon: Icon(Icons.person),
            label: 'Account',
          ),
        ],
      ),
    );
  }
}

class CatalogSelection extends ChangeNotifier {
  final Map<String, MedicineVariant> selected = {};

  void toggle(MedicineVariant medicine) {
    if (selected.containsKey(medicine.id)) {
      selected.remove(medicine.id);
    } else {
      if (selected.length >= 10) {
        return;
      }
      selected[medicine.id] = medicine;
    }
    notifyListeners();
  }
}

class SearchTab extends StatefulWidget {
  const SearchTab({super.key});
  @override
  State<SearchTab> createState() => _SearchTabState();
}

class _SearchTabState extends State<SearchTab> {
  final query = TextEditingController();
  List<MedicineVariant> results = [];
  String? error;
  bool loading = false;

  @override
  void dispose() {
    query.dispose();
    super.dispose();
  }

  Future<void> _search() async {
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final found = await context.read<DiscoveryRepository>().search(
        query.text.trim(),
      );
      setState(() => results = found);
    } catch (err) {
      setState(() => error = problemLabel(err));
    } finally {
      setState(() => loading = false);
    }
  }

  Future<void> _showGeneralUse(MedicineVariant medicine) async {
    try {
      final result = await context.read<DiscoveryRepository>().generalUse(
        medicine.id,
      );
      if (!mounted) {
        return;
      }
      final body = result.available && result.text != null
          ? result.text!
          : 'No general-use text is published for this medicine.';
      await showDialog<void>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('General use'),
          content: SingleChildScrollView(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(body),
                const SizedBox(height: 12),
                Text(result.disclaimer),
                const SizedBox(height: 8),
                const Text('This is not dosage advice.'),
              ],
            ),
          ),
          actions: [
            TextButton(
              key: const Key('close-general-use'),
              onPressed: () => Navigator.pop(context),
              child: const Text('Close'),
            ),
          ],
        ),
      );
    } catch (err) {
      if (mounted) {
        setState(() => error = problemLabel(err));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final selection = context.watch<CatalogSelection>();
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Semantics(
          label: 'Search medicines',
          textField: true,
          child: TextField(
            key: const Key('search-query'),
            controller: query,
            decoration: const InputDecoration(
              labelText: 'Search medicines',
              hintText: 'At least two characters',
            ),
            onSubmitted: (_) => _search(),
          ),
        ),
        const SizedBox(height: 8),
        FilledButton(
          key: const Key('search-submit'),
          onPressed: loading ? null : _search,
          child: Text(loading ? 'Searching' : 'Search'),
        ),
        if (error != null)
          Text(
            error!,
            semanticsLabel: 'Error: $error',
            style: const TextStyle(color: Colors.red),
          ),
        const SizedBox(height: 8),
        const Text(
          'Availability is straight-line distance, not travel time. Stock is not reserved until a pharmacist confirms.',
        ),
        ...results.map((medicine) {
          final selected = selection.selected.containsKey(medicine.id);
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              CheckboxListTile(
                key: Key('medicine-${medicine.id}'),
                value: selected,
                title: Text(medicine.title),
                subtitle: Text(
                  medicine.prescriptionRequired
                      ? 'Prescription required. Catalog checkout in 7.1 cannot order this. Prescription ordering arrives in 7.2.'
                      : 'Catalog medicine',
                ),
                onChanged: medicine.prescriptionRequired
                    ? null
                    : (_) => selection.toggle(medicine),
              ),
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton(
                  key: Key('general-use-${medicine.id}'),
                  onPressed: () => _showGeneralUse(medicine),
                  child: const Text('General use'),
                ),
              ),
            ],
          );
        }),
        if (selection.selected.isNotEmpty)
          FilledButton(
            key: const Key('find-pharmacies'),
            onPressed: () => Navigator.of(context).push(
              MaterialPageRoute<void>(builder: (_) => const AvailabilityPage()),
            ),
            child: const Text('Find pharmacies for selected medicines'),
          ),
      ],
    );
  }
}

class AvailabilityPage extends StatefulWidget {
  const AvailabilityPage({super.key});
  @override
  State<AvailabilityPage> createState() => _AvailabilityPageState();
}

class _AvailabilityPageState extends State<AvailabilityPage> {
  String? error;
  bool loading = true;
  List<PharmacyAvailability> pharmacies = [];
  String confirmation = '';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    final selection = context.read<CatalogSelection>();
    final profiles = context.read<ProfileRepository>();
    final location = context.read<LocationSource>();
    final discovery = context.read<DiscoveryRepository>();
    final addresses = await profiles.addresses();
    if (!mounted) {
      return;
    }
    setState(() {
      loading = true;
      error = null;
    });
    try {
      String? addressId;
      String? latitude;
      String? longitude;
      if (addresses.isNotEmpty) {
        final chosen = addresses.firstWhere(
          (row) => row.isDefault,
          orElse: () => addresses.first,
        );
        addressId = chosen.id;
      } else {
        final coords = await location.current();
        if (coords == null) {
          if (!mounted) {
            return;
          }
          setState(() {
            loading = false;
            error = 'Save an address with coordinates, or allow location. Street text is not geocoded.';
          });
          return;
        }
        latitude = coords.latitude.toString();
        longitude = coords.longitude.toString();
      }
      final result = await discovery.availability(
        medicineIds: selection.selected.keys.toList(),
        addressId: addressId,
        latitude: latitude,
        longitude: longitude,
      );
      setState(() {
        pharmacies = result.pharmacies;
        confirmation = result.confirmation;
        loading = false;
      });
    } catch (err) {
      setState(() {
        error = problemLabel(err);
        loading = false;
      });
    }
  }

  Future<void> _usePharmacy(PharmacyAvailability pharmacy) async {
    final selection = context.read<CatalogSelection>();
    final cartRepo = context.read<CartRepository>();
    final existing = await cartRepo.get();
    if (!mounted) {
      return;
    }
    if (existing != null && existing.pharmacyId != pharmacy.id) {
      final proceed = await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('Replace cart?'),
          content: const Text(
            'Changing pharmacy replaces the current cart. Items, notes, and any prescription are not kept.',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Keep cart'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Replace cart'),
            ),
          ],
        ),
      );
      if (proceed != true) {
        return;
      }
    }
    try {
      await cartRepo.replace({
        if (existing != null) 'version': existing.version,
        'pharmacy_id': pharmacy.id,
        'mode': 'catalog_items',
        'items': [
          for (final medicine in selection.selected.values)
            {'medicine_id': medicine.id, 'quantity': 1},
        ],
      });
      if (mounted) {
        Navigator.pop(context);
        ScaffoldMessenger.of(context)
            .showSnackBar(const SnackBar(content: Text('Cart updated')));
      }
    } catch (err) {
      if (err is ApiProblem && err.code == 'cart_stale') {
        await cartRepo.get();
      }
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(problemLabel(err))));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Nearby pickup')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (loading) const LinearProgressIndicator(),
          if (error != null)
            Text(error!, style: const TextStyle(color: Colors.red)),
          if (confirmation.isNotEmpty) Text(confirmation),
          if (!loading && pharmacies.isEmpty && error == null)
            const Text(
              'No pharmacy can supply every selected medicine nearby.',
            ),
          ...pharmacies.map(
            (pharmacy) => ListTile(
              title: Text(pharmacy.name),
              subtitle: Text(
                '${pharmacy.distanceMeters} meters straight-line. Not travel time. ${pharmacy.address}',
              ),
              trailing: FilledButton(
                key: Key('use-pharmacy-${pharmacy.id}'),
                onPressed: () => _usePharmacy(pharmacy),
                child: const Text('Use pharmacy'),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class CartTab extends StatefulWidget {
  const CartTab({super.key});
  @override
  State<CartTab> createState() => _CartTabState();
}

class _CartTabState extends State<CartTab> {
  Cart? cart;
  String? error;
  bool loading = true;
  bool submitting = false;
  bool recovered = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _reload(recover: true));
  }

  Future<void> _reload({bool recover = false}) async {
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final loaded = await context.read<CartRepository>().get();
      setState(() {
        cart = loaded;
        loading = false;
      });
      if (recover && !recovered) {
        recovered = true;
        await _recoverPending();
      }
    } catch (err) {
      setState(() {
        error = problemLabel(err);
        loading = false;
      });
    }
  }

  Future<void> _recoverPending() async {
    final session = context.read<SessionController>();
    if (!session.canRetryPendingFor(session.customer?.id)) {
      return;
    }
    await _submit(newIntent: false);
  }

  Future<void> _submit({required bool newIntent}) async {
    final session = context.read<SessionController>();
    final orders = context.read<OrderRepository>();
    final current = cart;
    var pending = session.pendingSubmit;
    final ownerId = session.customer?.id;

    if (current != null && current.requestMode != 'catalog_items') {
      setState(() => error = 'Prescription carts are not available in 7.1.');
      return;
    }

    if (pending != null) {
      if (!session.isSignedIn || ownerId == null) {
        return;
      }
      if (pending.customerId != ownerId) {
        await session.clearPending();
        pending = null;
        if (!newIntent) {
          return;
        }
      }
    }

    if (pending != null &&
        current != null &&
        (pending.cartVersion != current.version ||
            pending.pharmacyId != current.pharmacyId)) {
      if (newIntent && ownerId != null) {
        pending = PendingSubmit(
          idempotencyKey: PendingSubmit.generateKey(),
          cartVersion: current.version,
          pharmacyId: current.pharmacyId,
          customerId: ownerId,
        );
        await session.savePending(pending);
      } else {
        await session.clearPending();
        setState(() => error = 'The cart changed. Review it and submit again.');
        return;
      }
    }

    if (pending == null) {
      if (!newIntent ||
          current == null ||
          !session.isSignedIn ||
          ownerId == null) {
        return;
      }
      pending = PendingSubmit(
        idempotencyKey: PendingSubmit.generateKey(),
        cartVersion: current.version,
        pharmacyId: current.pharmacyId,
        customerId: ownerId,
      );
      await session.savePending(pending);
    }

    setState(() => submitting = true);
    try {
      final result = await orders.submit(
        cartVersion: pending.cartVersion,
        idempotencyKey: pending.idempotencyKey,
      );
      await session.clearPending();
      if (!mounted) {
        return;
      }
      final replay = result.statusCode == 200
          ? ' Same order replayed; no duplicate was created.'
          : '';
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Order ${result.order.reference} submitted.$replay'),
        ),
      );
      if (mounted) {
        context.go('/orders/${result.order.id}');
      }
    } catch (err) {
      if (err is ApiProblem && err.code == 'cart_stale') {
        await session.clearPending();
        await _reload();
      }
      if (mounted) {
        setState(() => error = problemLabel(err));
      }
    } finally {
      if (mounted) {
        setState(() => submitting = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final pending = context.watch<SessionController>().pendingSubmit;
    if (loading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (cart == null) {
      return ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (error != null)
            Text(error!, style: const TextStyle(color: Colors.red)),
          const Text('Your cart is empty.'),
          if (pending != null &&
              context.read<SessionController>().canRetryPendingFor(
                context.read<SessionController>().customer?.id,
              ))
            FilledButton(
              key: const Key('retry-submit'),
              onPressed: submitting ? null : () => _submit(newIntent: false),
              child: const Text('Retry previous submit'),
            ),
          TextButton(onPressed: _reload, child: const Text('Reload cart')),
        ],
      );
    }
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        if (error != null)
          Text(error!, style: const TextStyle(color: Colors.red)),
        Text(
          'Pharmacy cart version ${cart!.version}. Prices are estimates in paisa.',
        ),
        ...cart!.items.map(
          (item) => ListTile(
            title: Text(item.genericName ?? item.medicineId),
            subtitle: Text(
              'Qty ${item.quantity}. ${item.unitPricePaisa == null ? 'No estimate' : formatPaisa(item.unitPricePaisa!)}. ${item.eligible ? 'Eligible' : 'Not eligible'}',
            ),
          ),
        ),
        FilledButton(
          key: const Key('submit-pickup'),
          onPressed: submitting ? null : () => _submit(newIntent: true),
          child: Text(submitting ? 'Submitting' : 'Submit pickup request'),
        ),
        if (pending != null &&
            context.read<SessionController>().canRetryPendingFor(
              context.read<SessionController>().customer?.id,
            ))
          TextButton(
            key: const Key('retry-submit'),
            onPressed: submitting ? null : () => _submit(newIntent: false),
            child: const Text('Retry previous submit'),
          ),
        const Text(
          'Submitting asks the pharmacy to review. It does not reserve stock or collect payment.',
        ),
      ],
    );
  }
}

class OrdersTab extends StatefulWidget {
  const OrdersTab({super.key});
  @override
  State<OrdersTab> createState() => _OrdersTabState();
}

class _OrdersTabState extends State<OrdersTab> {
  List<CustomerOrder> orders = [];
  String? error;
  bool loading = true;
  bool loadingMore = false;
  bool hasMore = false;
  String? nextCursor;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load(refresh: true));
  }

  Future<void> _load({required bool refresh}) async {
    setState(() {
      if (refresh) {
        loading = true;
      } else {
        loadingMore = true;
      }
      error = null;
    });
    try {
      final page = await context.read<OrderRepository>().list(
        cursor: refresh ? null : nextCursor,
      );
      setState(() {
        if (refresh) {
          orders = page.orders;
        } else {
          orders = [...orders, ...page.orders];
        }
        hasMore = page.hasMore;
        nextCursor = page.nextCursor;
        loading = false;
        loadingMore = false;
      });
    } catch (err) {
      setState(() {
        error = problemLabel(err);
        loading = false;
        loadingMore = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    if (loading) {
      return const Center(child: CircularProgressIndicator());
    }
    return RefreshIndicator(
      onRefresh: () => _load(refresh: true),
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          if (error != null)
            ListTile(
              title: Text(error!, style: const TextStyle(color: Colors.red)),
            ),
          if (orders.isEmpty) const ListTile(title: Text('No orders yet.')),
          ...orders.map(
            (order) => ListTile(
              key: Key('order-${order.id}'),
              title: Text(order.reference),
              subtitle: Text(order.statusText),
              onTap: () => context.push('/orders/${order.id}'),
            ),
          ),
          if (hasMore)
            TextButton(
              key: const Key('load-more-orders'),
              onPressed: loadingMore ? null : () => _load(refresh: false),
              child: Text(loadingMore ? 'Loading' : 'Load more orders'),
            ),
        ],
      ),
    );
  }
}

class OrderDetailScreen extends StatefulWidget {
  const OrderDetailScreen({super.key, required this.orderId});
  final String orderId;
  @override
  State<OrderDetailScreen> createState() => _OrderDetailScreenState();
}

class _OrderDetailScreenState extends State<OrderDetailScreen> {
  CustomerOrder? order;
  String? error;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    try {
      final loaded = await context.read<OrderRepository>().show(widget.orderId);
      setState(() => order = loaded);
    } catch (err) {
      setState(() => error = problemLabel(err));
    }
  }

  Future<void> _cancel() async {
    try {
      final loaded = await context.read<OrderRepository>().cancel(
        widget.orderId,
      );
      setState(() => order = loaded);
    } catch (err) {
      setState(() => error = problemLabel(err));
    }
  }

  String _statusCopy(CustomerOrder order) {
    return switch (order.status) {
      'pending_pharmacy_review' => 'This is a request. The pharmacy has not confirmed it. Estimates are not a reservation.',
      'confirmed' =>
        'The pharmacy confirmed this request. It is not ready for pickup yet.',
      'ready_for_pickup' => 'Ready for pickup. Pay cash at collection. Unpaid until the pharmacy records collection.',
      'collected' =>
        order.paymentStatus == 'paid'
            ? 'Collected. Cash recorded: ${formatPaisa(order.cashCollectedPaisa ?? 0)}.'
            : order.paymentStatus == 'no_payment_due'
            ? 'Collected. No payment was due.'
            : 'Collected.',
      'cancelled' => 'This order was cancelled.',
      'rejected' => 'The pharmacy rejected this request.',
      'not_collected' => 'Not collected by the end of the pickup window.',
      'unfulfillable' => 'This order cannot be collected.',
      _ => order.statusText,
    };
  }

  @override
  Widget build(BuildContext context) {
    final current = order;
    return Scaffold(
      appBar: AppBar(title: Text(current?.reference ?? 'Order')),
      body: current == null
          ? Center(
              child: error == null
                  ? const CircularProgressIndicator()
                  : Text(error!),
            )
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                if (error != null)
                  Text(error!, style: const TextStyle(color: Colors.red)),
                Text(_statusCopy(current)),
                if (current.quote != null)
                  Text(
                    current.quote!.accepted
                        ? 'Quote accepted. Still awaiting pharmacist confirmation. Not reserved.'
                        : 'Open quote ${formatPaisa(current.quote!.totalPaisa)} expires ${formatKarachi(current.quote!.expiresAt)}. Quote acceptance is Phase 7.2.',
                  ),
                if (current.pickupWindow != null) ...[
                  Text(
                    'Pickup starts ${formatKarachi(current.pickupWindow!.startsAt)}',
                  ),
                  Text(
                    'Pickup ends ${formatKarachi(current.pickupWindow!.endsAt)}',
                  ),
                  Text(
                    'Collect until ${formatKarachi(current.pickupWindow!.collectUntil)}',
                  ),
                ],
                Text(
                  'Payment method: cash on pickup. Status: ${current.paymentStatus}.',
                ),
                ...current.timeline.map(
                  (event) => ListTile(
                    title: Text('${event.event} (${event.status})'),
                    subtitle: Text(
                      '${formatKarachi(event.at)} · ${event.actor}',
                    ),
                  ),
                ),
                if (current.canCancel)
                  FilledButton(
                    key: const Key('cancel-order'),
                    onPressed: _cancel,
                    child: const Text('Cancel order'),
                  ),
              ],
            ),
    );
  }
}

class AccountTab extends StatelessWidget {
  const AccountTab({super.key});
  @override
  Widget build(BuildContext context) {
    final session = context.watch<SessionController>();
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text('Signed in as ${session.customer?.phoneMasked ?? ''}'),
        ListTile(
          key: const Key('open-addresses'),
          title: const Text('Addresses'),
          onTap: () => context.push('/addresses'),
        ),
        FilledButton(
          onPressed: () async {
            await context.read<AuthRepository>().logout();
            await session.clearAuth();
          },
          child: const Text('Log out this device'),
        ),
        TextButton(
          onPressed: () async {
            await context.read<AuthRepository>().logoutAll();
            await session.clearAuth();
          },
          child: const Text('Log out all devices'),
        ),
      ],
    );
  }
}

class AddressScreen extends StatefulWidget {
  const AddressScreen({super.key});
  @override
  State<AddressScreen> createState() => _AddressScreenState();
}

class _AddressScreenState extends State<AddressScreen> {
  final label = TextEditingController(text: 'Home');
  final street = TextEditingController();
  final latitude = TextEditingController();
  final longitude = TextEditingController();
  String source = 'not set';
  String? error;
  List<SavedAddress> addresses = [];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    try {
      final loaded = await context.read<ProfileRepository>().addresses();
      setState(() => addresses = loaded);
    } catch (err) {
      setState(() => error = problemLabel(err));
    }
  }

  Future<void> _gps() async {
    final coords = await context.read<LocationSource>().current();
    if (coords == null) {
      setState(
        () => error = 'Location permission was not granted. Enter coordinates yourself. Street text is not geocoded.',
      );
      return;
    }
    setState(() {
      latitude.text = coords.latitude.toString();
      longitude.text = coords.longitude.toString();
      source = coords.source;
    });
  }

  Future<void> _save() async {
    setState(() => error = null);
    try {
      await context.read<ProfileRepository>().createAddress({
        'label': label.text.trim(),
        'address_text': street.text.trim(),
        'latitude': double.parse(latitude.text.trim()),
        'longitude': double.parse(longitude.text.trim()),
        'is_default': true,
      });
      await _load();
    } catch (err) {
      setState(() => error = problemLabel(err));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Addresses')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          const Text(
            'Coordinates come from device GPS or numbers you type. The street line is a label only. It is not geocoded, and MediFind does not invent a city-centre pin.',
          ),
          TextField(
            key: const Key('address-label'),
            controller: label,
            decoration: const InputDecoration(labelText: 'Label'),
          ),
          TextField(
            key: const Key('address-street'),
            controller: street,
            decoration: const InputDecoration(labelText: 'Street description'),
          ),
          TextField(
            key: const Key('address-latitude'),
            controller: latitude,
            decoration: const InputDecoration(labelText: 'Latitude'),
            keyboardType: TextInputType.number,
          ),
          TextField(
            key: const Key('address-longitude'),
            controller: longitude,
            decoration: const InputDecoration(labelText: 'Longitude'),
            keyboardType: TextInputType.number,
          ),
          Text('Coordinate source: $source'),
          FilledButton(onPressed: _gps, child: const Text('Use my location')),
          FilledButton(
            key: const Key('save-address'),
            onPressed: _save,
            child: const Text('Save address'),
          ),
          if (error != null)
            Text(error!, style: const TextStyle(color: Colors.red)),
          ...addresses.map(
            (row) => ListTile(
              title: Text(row.label),
              subtitle: Text(
                '${row.addressText} (${row.latitude}, ${row.longitude})',
              ),
            ),
          ),
        ],
      ),
    );
  }
}
