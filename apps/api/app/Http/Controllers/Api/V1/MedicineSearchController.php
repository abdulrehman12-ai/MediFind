<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Discovery\CatalogSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\SearchMedicinesRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class MedicineSearchController extends Controller
{
    public function __construct(private readonly CatalogSearch $search) {}

    public function index(SearchMedicinesRequest $request): JsonResponse
    {
        $result = $this->search->paginate(
            $request->normalizedQuery(),
            $request->pageNumber(),
            $request->pageSize(),
        );

        return ApiResponse::data($request, $result['items'], 200, [
            'page' => $result['page'],
            'per_page' => $result['per_page'],
            'has_more' => $result['has_more'],
        ]);
    }
}
