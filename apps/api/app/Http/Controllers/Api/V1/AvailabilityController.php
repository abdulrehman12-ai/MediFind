<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Discovery\NearbyAvailability;
use App\Exceptions\ApiProblem;
use App\Http\Controllers\Controller;
use App\Http\Requests\NearbyAvailabilityRequest;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class AvailabilityController extends Controller
{
    public function __construct(private readonly NearbyAvailability $availability) {}

    public function index(NearbyAvailabilityRequest $request): JsonResponse
    {
        $customer = $request->user();

        if (! $customer instanceof User) {
            throw new ApiProblem('unauthenticated', 'Unauthenticated.', 401);
        }

        $result = $this->availability->paginate(
            $customer,
            $request->medicineIds(),
            $request->addressId(),
            $request->latitude(),
            $request->longitude(),
            $request->radiusMeters(),
            $request->pageNumber(),
            $request->pageSize(),
        );

        return ApiResponse::data($request, $result['items'], 200, [
            'page' => $result['page'],
            'per_page' => $result['per_page'],
            'has_more' => $result['has_more'],
            'radius_meters' => $result['radius_meters'],
            'freshness_hours' => $result['freshness_hours'],
            'distance_method' => 'straight_line',
            'confirmation' => NearbyAvailability::CONFIRMATION,
        ]);
    }
}
