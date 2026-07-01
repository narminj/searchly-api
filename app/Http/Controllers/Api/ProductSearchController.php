<?php

namespace App\Http\Controllers\Api;

use App\Contracts\SearchRepositoryInterface;
use App\Events\SearchPerformed;
use App\Http\Controllers\Controller;
use App\Http\Requests\SearchProductsRequest;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

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
     *   with_aggs     - boolean (default 1): include facet aggregations.
     *                   Send 0 beyond page 1 — they're the most expensive part.
     *
     * Zero-result responses include suggested_query ("did you mean") when
     * the phrase suggester has a correction.
     */
    #[OA\Get(
        path: '/products/search',
        summary: 'Search products',
        description: 'Full-text product search (AZ/EN, fuzzy, synonyms) with filters, '
            . 'sorting, faceted aggregations, highlighting, geo-distance and cursor pagination. '
            . 'Zero-result responses include suggested_query ("did you mean").',
        tags: ['Search'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', description: 'Free-text query', schema: new OA\Schema(type: 'string', maxLength: 200)),
            new OA\Parameter(name: 'category', in: 'query', description: 'Single category filter', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'categories[]', in: 'query', description: 'Multiple categories (OR, max 10)', schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string'))),
            new OA\Parameter(name: 'brand', in: 'query', description: 'Single brand filter', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'brands[]', in: 'query', description: 'Multiple brands (OR, max 10)', schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string'))),
            new OA\Parameter(name: 'tags[]', in: 'query', description: 'Tag filters (AND, max 10)', schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string'))),
            new OA\Parameter(name: 'price_min', in: 'query', schema: new OA\Schema(type: 'number', format: 'float')),
            new OA\Parameter(name: 'price_max', in: 'query', schema: new OA\Schema(type: 'number', format: 'float')),
            new OA\Parameter(name: 'in_stock', in: 'query', description: 'Only in-stock products', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['relevance', 'price_asc', 'price_desc', 'newest', 'oldest', 'name', 'stock_desc'], default: 'relevance')),
            new OA\Parameter(name: 'page', in: 'query', description: 'Page number (max = 10000/per_page)', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 100, default: 15)),
            new OA\Parameter(name: 'cursor', in: 'query', description: 'search_after cursor; when set, page/from are ignored', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'with_aggs', in: 'query', description: 'Include facet aggregations (default 1; send 0 beyond page 1)', schema: new OA\Schema(type: 'boolean', default: true)),
            new OA\Parameter(name: 'from_date', in: 'query', description: 'created_at >= (Y-m-d)', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to_date', in: 'query', description: 'created_at <= (Y-m-d)', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'geo_lat', in: 'query', schema: new OA\Schema(type: 'number', format: 'float')),
            new OA\Parameter(name: 'geo_lon', in: 'query', schema: new OA\Schema(type: 'number', format: 'float')),
            new OA\Parameter(name: 'geo_distance', in: 'query', description: 'Radius, e.g. 50km / 100m / 30mi', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Search results with pagination, aggregations and timing', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'total', type: 'integer', example: 47),
                    new OA\Property(property: 'total_is_exact', type: 'boolean', example: true),
                    new OA\Property(property: 'per_page', type: 'integer', example: 15),
                    new OA\Property(property: 'current_page', type: 'integer', example: 1),
                    new OA\Property(property: 'last_page', type: 'integer', example: 4),
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                    new OA\Property(property: 'aggregations', type: 'object', nullable: true),
                    new OA\Property(property: 'suggested_query', type: 'string', nullable: true),
                    new OA\Property(property: 'took_ms', type: 'integer', example: 12),
                    new OA\Property(property: 'max_score', type: 'number', format: 'float', nullable: true),
                ]
            )),
            new OA\Response(response: 422, description: 'Validation error (e.g. page over cap, invalid cursor)'),
            new OA\Response(response: 429, description: 'Rate limit exceeded (60/min)'),
            new OA\Response(response: 503, description: 'Elasticsearch unavailable'),
        ]
    )]
    public function search(SearchProductsRequest $request): JsonResponse
    {
        $query   = $request->searchQuery();
        $filters = $request->filters();

        // Tenant isolation — resolved from X-Tenant-ID (default tenant otherwise)
        $options = $request->options();
        $options['tenant'] = $this->resolveTenant($request);

        $result = $this->repository->search($query, $filters, $options);

        // Analytics, off the request path (queued listener). Session is an
        // anonymous hash — no PII leaves the request.
        SearchPerformed::dispatch(
            $query,
            $filters,
            $result['total'] ?? 0,
            $result['took_ms'] ?? 0,
            $request->page(),
            $result['suggested_query'] ?? null,
            sha1($request->ip() . '|' . $request->userAgent()),
        );

        return response()->json($result);
    }

    /**
     * POST /api/products/{id}/click
     *
     * Click-through tracking: atomically bumps the product's popularity
     * counter. Query-builder increment on purpose — no model events, so a
     * click never triggers a reindex job (the nightly in-place reindex
     * carries popularity into Elasticsearch).
     */
    #[OA\Post(
        path: '/products/{id}/click',
        summary: 'Track a product click',
        description: 'Atomically increments the product popularity counter (feeds relevance boosting). No reindex job is triggered.',
        tags: ['Products'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 204, description: 'Click recorded'),
            new OA\Response(response: 404, description: 'Product not found'),
            new OA\Response(response: 429, description: 'Rate limit exceeded'),
        ]
    )]
    public function click(Request $request, int $id): Response|JsonResponse
    {
        // Scoped to the tenant so a click can't touch another tenant's product.
        // Gated until the tenant_id column exists (see config 'multi_tenancy').
        $updated = Product::query()
            ->when(
                config('elasticsearch.multi_tenancy'),
                fn ($q) => $q->where('tenant_id', $this->resolveTenant($request)),
            )
            ->whereKey($id)
            ->increment('popularity');

        if ($updated === 0) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        return response()->noContent();
    }

    /**
     * Resolve the request's tenant from the X-Tenant-ID header, falling back to
     * the configured default. Only safe identifiers are accepted; anything else
     * (or a missing header) yields the default tenant.
     */
    private function resolveTenant(Request $request): string
    {
        $tenant = (string) $request->header('X-Tenant-ID', '');

        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $tenant)
            ? $tenant
            : config('elasticsearch.default_tenant');
    }

    /**
     * GET /api/products/{id}
     *
     * Fetch a single product document from Elasticsearch by ID.
     */
    #[OA\Get(
        path: '/products/{id}',
        summary: 'Get a product by ID',
        description: 'Fetch a single product document from Elasticsearch.',
        tags: ['Products'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Product document', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 404, description: 'Product not found'),
        ]
    )]
    public function show(Request $request, int $id): JsonResponse
    {
        $product = $this->repository->findById($id, $this->resolveTenant($request));

        if (empty($product)) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        return response()->json(['data' => $product]);
    }

    /**
     * GET /api/products/suggest?q=sam
     *
     * Autocomplete suggestions: completion suggester (fuzzy) with a
     * search_as_you_type bool_prefix fallback. Returns up to 10 name strings.
     */
    #[OA\Get(
        path: '/products/suggest',
        summary: 'Autocomplete suggestions',
        description: 'Prefix-based product name suggestions (completion suggester + search_as_you_type fallback). Minimum 2 characters.',
        tags: ['Suggest'],
        parameters: [new OA\Parameter(name: 'q', in: 'query', required: true, schema: new OA\Schema(type: 'string', minLength: 2, maxLength: 100))],
        responses: [
            new OA\Response(response: 200, description: 'Up to 10 suggestions', content: new OA\JsonContent(properties: [new OA\Property(property: 'suggestions', type: 'array', items: new OA\Items(type: 'string'))])),
            new OA\Response(response: 422, description: 'Validation error (q too short)'),
            new OA\Response(response: 429, description: 'Rate limit exceeded (120/min)'),
        ]
    )]
    public function suggest(Request $request): JsonResponse
    {
        $request->validate(['q' => 'required|string|min:2|max:100']);

        $suggestions = $this->repository->suggest(
            $request->string('q')->toString(),
            $this->resolveTenant($request),
        );

        return response()->json(['suggestions' => $suggestions]);
    }
}
