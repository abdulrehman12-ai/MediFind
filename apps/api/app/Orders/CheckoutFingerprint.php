<?php

declare(strict_types=1);

namespace App\Orders;

final class CheckoutFingerprint
{
    /**
     * @param  list<array{medicine_id: string, quantity: int}>  $items
     */
    public static function hash(
        int $cartVersion,
        string $pharmacyId,
        string $mode,
        ?string $note,
        ?string $prescriptionPublicId,
        ?int $contentVersion,
        ?string $shareMode,
        array $items,
    ): string {
        $lines = $items;
        usort($lines, static fn (array $left, array $right): int => $left['medicine_id'] <=> $right['medicine_id']);

        $payload = json_encode([
            'cart_version' => $cartVersion,
            'items' => array_map(static fn (array $line): array => [
                'medicine_id' => $line['medicine_id'],
                'quantity' => $line['quantity'],
            ], $lines),
            'mode' => $mode,
            'note' => $note,
            'pharmacy_id' => $pharmacyId,
            'prescription_content_version' => $contentVersion,
            'prescription_id' => $prescriptionPublicId,
            'share_mode' => $shareMode,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $payload);
    }
}
