<?php

declare(strict_types=1);

namespace App\Orders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class OrderHistory
{
    /**
     * @return array{rows: list<Order>, has_more: bool, next_cursor: string|null}
     */
    public function listOwned(User $customer, mixed $cursor): array
    {
        $pageSize = (int) config('medifind.orders.history_page_size');
        $query = Order::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
        $position = $this->cursor($cursor);

        if ($position !== null) {
            [$createdAt, $id] = $position;
            $query->where(function ($older) use ($createdAt, $id): void {
                $older->where('created_at', '<', $createdAt)
                    ->orWhere(function ($sameTime) use ($createdAt, $id): void {
                        $sameTime->where('created_at', $createdAt)->where('id', '<', $id);
                    });
            });
        }

        $rows = $query->limit($pageSize + 1)->get();
        $hasMore = $rows->count() > $pageSize;
        $page = $rows->take($pageSize)->values();
        $last = $page->last();

        return [
            'rows' => $page->all(),
            'has_more' => $hasMore,
            'next_cursor' => $hasMore && $last instanceof Order ? $this->encode($last) : null,
        ];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function cursor(mixed $cursor): ?array
    {
        if ($cursor === null) {
            return null;
        }

        if (! is_string($cursor) || $cursor === '') {
            throw ValidationException::withMessages(['cursor' => ['The cursor is invalid.']]);
        }

        $padded = $cursor.str_repeat('=', (4 - strlen($cursor) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        $payload = is_string($decoded) ? json_decode($decoded, true) : null;
        $createdAt = is_array($payload) ? ($payload['created_at'] ?? null) : null;
        $id = is_array($payload) ? ($payload['id'] ?? null) : null;
        $parsed = false;

        if (is_string($createdAt)) {
            try {
                $parsed = Carbon::createFromFormat('!Y-m-d H:i:s.u', $createdAt, 'UTC');
            } catch (Throwable) {
                $parsed = false;
            }
        }

        if (! $parsed instanceof Carbon
            || ! is_string($createdAt)
            || $parsed->format('Y-m-d H:i:s.u') !== $createdAt
            || ! is_string($id)
            || ! Str::isUuid($id)) {
            throw ValidationException::withMessages(['cursor' => ['The cursor is invalid.']]);
        }

        return [$createdAt, $id];
    }

    private function encode(Order $order): string
    {
        $createdAt = $order->created_at instanceof Carbon
            ? $order->created_at->copy()->utc()->format('Y-m-d H:i:s.u')
            : Carbon::parse((string) $order->created_at)->utc()->format('Y-m-d H:i:s.u');

        return rtrim(strtr(base64_encode((string) json_encode([
            'created_at' => $createdAt,
            'id' => $order->id,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
