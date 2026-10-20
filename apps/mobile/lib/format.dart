DateTime parseInstant(String raw) {
  final parsed = DateTime.parse(raw);
  return parsed.toUtc();
}

DateTime? parseInstantOrNull(Object? raw) {
  if (raw is! String || raw.isEmpty) {
    return null;
  }
  return parseInstant(raw);
}

/// Pakistan Standard Time is UTC+05:00 year-round.
const Duration karachiOffset = Duration(hours: 5);

String formatKarachi(DateTime utc) {
  final local = utc.toUtc().add(karachiOffset);
  final y = local.year.toString().padLeft(4, '0');
  final m = local.month.toString().padLeft(2, '0');
  final d = local.day.toString().padLeft(2, '0');
  final hh = local.hour.toString().padLeft(2, '0');
  final mm = local.minute.toString().padLeft(2, '0');
  return '$y-$m-$d $hh:$mm Asia/Karachi';
}

String formatPaisa(int paisa) {
  final rupees = paisa / 100;
  return 'Rs ${rupees.toStringAsFixed(2)}';
}
