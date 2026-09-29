<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiProblem;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClearCartRequest;
use App\Http\Requests\ReplaceCartRequest;
use App\Models\User;
use App\Orders\CartService;
use App\Orders\OrderPresenter;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class CartController extends Controller
{
    public function __construct(
        private readonly CartService $carts,
        private readonly OrderPresenter $presenter,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $cart = $this->presenter->cart($this->carts->show($this->customer($request)));

        if ($cart === null) {
            return response()->json([
                'data' => null,
                'meta' => ['request_id' => $request->headers->get('X-Request-Id')],
            ]);
        }

        return ApiResponse::data($request, $cart);
    }

    public function replace(ReplaceCartRequest $request): JsonResponse
    {
        $cart = $this->carts->replace($this->customer($request), $request->validated());

        return ApiResponse::data($request, $this->presenter->cart($cart) ?? []);
    }

    public function clear(ClearCartRequest $request): Response
    {
        $version = $request->validated('version');
        $this->carts->clear($this->customer($request), is_int($version) ? $version : (int) $version);

        return ApiResponse::noContent();
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
