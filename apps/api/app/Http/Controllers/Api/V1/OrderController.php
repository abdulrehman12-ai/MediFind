<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiProblem;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelOrderRequest;
use App\Http\Requests\SubmitOrderRequest;
use App\Models\User;
use App\Orders\OrderPresenter;
use App\Orders\OrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly OrderPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $cursor = $request->query->has('cursor')
            ? ($request->query('cursor') ?? '')
            : null;
        $page = $this->orders->list($this->customer($request), $cursor);
        $payload = [];

        foreach ($page['rows'] as $order) {
            $payload[] = $this->presenter->order($order);
        }

        return ApiResponse::data($request, $payload, 200, [
            'has_more' => $page['has_more'],
            'next_cursor' => $page['next_cursor'],
            'per_page' => (int) config('medifind.orders.history_page_size'),
        ]);
    }

    public function store(SubmitOrderRequest $request): JsonResponse
    {
        $version = $request->validated('cart_version');
        $result = $this->orders->submit(
            $this->customer($request),
            is_int($version) ? $version : (int) $version,
            $request->idempotencyKey(),
        );

        return ApiResponse::data($request, $this->presenter->order($result['order']), $result['replayed'] ? 200 : 201);
    }

    public function show(Request $request, string $order): JsonResponse
    {
        return ApiResponse::data($request, $this->presenter->order(
            $this->orders->show($this->customer($request), $order),
        ));
    }

    public function cancel(CancelOrderRequest $request, string $order): JsonResponse
    {
        $reason = $request->validated('reason');

        return ApiResponse::data($request, $this->presenter->order(
            $this->orders->cancel($this->customer($request), $order, is_string($reason) ? $reason : null),
        ));
    }

    private function customer(Request $request): User
    {
        $customer = $request->user();

        if (! $customer instanceof User) {
            throw new ApiProblem('unauthenticated', 'Unauthenticated.', 401);
        }

        return $customer;
    }
}
