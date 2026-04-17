<?php

namespace App\Http\Controllers\Api;

use App\Contracts\SearchRepositoryInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductSearchController extends Controller
{
    public function __construct(private readonly SearchRepositoryInterface $repository) {}

    /**
     * GET /api/products/search
     *
     * Query params:
     *   q             - Free-text search query
     *   category      - Single category filter
     *   categories[]  - Multiple categories (OR)
     *   brand         - Single brand filter
     *   brands[]      - Multiple brands (OR)
     *   tags[]        - Tag filters (AND)
     *   price_min     - Minimum price
     *   price_max     - Maximum price
     *   in_stock      - boolean: only in-stock products
     *   sort          - relevance|price_asc|price_desc|newest|name
     *   page          - Page number (default: 1)
     *   per_page      - Results per page (default: 15, max: 100)
     *   from_date     - Filter by created_at >= (Y-m-d)
     *   to_date       - Filter by created_at <= (Y-m-d)
     *   geo_lat       - Latitude for geo-distance filter
     *   geo_lon       - Longitude for geo-distance filter
     *   geo_distance  - Distance radius (e.g. "50km")
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q'            => 'nullable|string|max:200',
            'category'     => 'nullable|string|max:100',
            'categories'   => 'nullable|array',
            'categories.*' => 'string|max:100',
            'brand'        => 'nullable|string|max:100',
            'brands'       => 'nullable|array',
            'brands.*'     => 'string|max:100',
            'tags'         => 'nullable|array',
            'tags.*'       => 'string|max:50',
            'price_min'    => 'nullable|numeric|min:0',
            'price_max'    => 'nullable|numeric|min:0',
            'in_stock'     => 'nullable|boolean',
            'sort'         => 'nullable|in:relevance,price_asc,price_desc,newest,oldest,name,stock_desc',
            'page'         => 'nullable|integer|min:1|max:1000',
            'per_page'     => 'nullable|integer|min:1|max:100',
            'from_date'    => 'nullable|date_format:Y-m-d',
            'to_date'      => 'nullable|date_format:Y-m-d',
            'geo_lat'      => 'nullable|numeric|between:-90,90',
            'geo_lon'      => 'nullable|numeric|between:-180,180',
            'geo_distance' => 'nullable|string|regex:/^\d+(\.\d+)?(km|m|mi|yd)$/',
        ]);

        $filters = array_filter([
            'category'   => $validated['category'] ?? null,
            'categories' => $validated['categories'] ?? null,
            'brand'      => $validated['brand'] ?? null,
            'brands'     => $validated['brands'] ?? null,
            'tags'       => $validated['tags'] ?? null,
            'price_min'  => $validated['price_min'] ?? null,
            'price_max'  => $validated['price_max'] ?? null,
            'in_stock'   => $validated['in_stock'] ?? null,
        ]);

        $options = array_filter([
            'sort'         => $validated['sort'] ?? null,
            'page'         => $validated['page'] ?? null,
            'per_page'     => $validated['per_page'] ?? null,
            'from_date'    => $validated['from_date'] ?? null,
            'to_date'      => $validated['to_date'] ?? null,
            'geo_lat'      => $validated['geo_lat'] ?? null,
            'geo_lon'      => $validated['geo_lon'] ?? null,
            'geo_distance' => $validated['geo_distance'] ?? null,
        ]);

        $result = $this->repository->search($validated['q'] ?? '', $filters, $options);

        return response()->json($result);
    }

    /**
     * GET /api/products/{id}
     *
     * Fetch a single product document from Elasticsearch by ID.
     */
    public function show(int $id): JsonResponse
    {
        $product = $this->repository->findById($id);

        if (empty($product)) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        return response()->json(['data' => $product]);
    }

    /**
     * GET /api/products/suggest?q=sam
     *
     * Autocomplete suggestions using edge n-gram on the name field.
     * Returns up to 10 product name strings.
     */
    public function suggest(Request $request): JsonResponse
    {
        $request->validate(['q' => 'required|string|min:2|max:100']);

        $suggestions = $this->repository->suggest($request->string('q')->toString());

        return response()->json(['suggestions' => $suggestions]);
    }
}
