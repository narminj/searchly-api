<?php

namespace App\Services\Repositories;

use App\Contracts\SearchRepositoryInterface;
use App\Services\ElasticsearchService;

/**
 * Product-specific Elasticsearch query builder.
 *
 * Demonstrates: multilingual (AZ/EN) full-text search with synonyms, fuzzy,
 * term/terms/range filters, bool queries, post_filter multi-select faceting,
 * aggregations (terms, range, avg/min/max/sum, cardinality), highlighting,
 * pagination, sorting, geo-distance, completion + search-as-you-type
 * autocomplete, and a phrase-suggester "did you mean".
 */
class ProductSearchRepository implements SearchRepositoryInterface
{
    private string $index;

    public function __construct(private readonly ElasticsearchService $es)
    {
        $this->index = config('elasticsearch.indices.products.name');
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Full search with all features: filters, sorting, pagination, aggs, highlights.
     *
     * Supported $filters keys:
     *   category, categories (array), brand, brands (array),
     *   tags (array), price_min, price_max, in_stock, is_active
     *
     * Supported $options keys:
     *   sort (relevance|price_asc|price_desc|newest|name),
     *   page, per_page, with_aggs (default true), from_date, to_date,
     *   geo_lat, geo_lon, geo_distance (e.g. "50km")
     */
    public function search(string $query, array $filters = [], array $options = []): array
    {
        $perPage  = (int) ($options['per_page'] ?? 15);
        $page     = (int) ($options['page'] ?? 1);
        $from     = ($page - 1) * $perPage;
        $withAggs = (bool) ($options['with_aggs'] ?? true);

        $facetFilters = $this->buildFacetFilters($filters);

        $body = [
            'size'      => $perPage,
            // Explicit cap: counting beyond this is wasted work, and the
            // response exposes total_is_exact so the UI can show "10,000+"
            'track_total_hits' => 10000,
            'query'     => $this->buildQuery($query, $filters, $options),
            'sort'      => $this->buildSort($options),
            'highlight' => $this->buildHighlight(),
        ];

        // Cursor pagination (search_after) for deep/infinite scrolling: not
        // limited by max_result_window. from/size and cursor are exclusive.
        if (! empty($options['cursor'])) {
            $body['search_after'] = $options['cursor'];
        } else {
            $body['from'] = $from;
        }

        // Selected facet values filter the HITS via post_filter (not the query),
        // so each facet's aggregation can ignore its own selection — selecting
        // "Samsung" keeps Apple/Sony counts visible (multi-select faceting)
        $allFacetClauses = array_merge(...array_values($facetFilters) ?: [[]]);
        if ($allFacetClauses) {
            $body['post_filter'] = ['bool' => ['filter' => $allFacetClauses]];
        }

        if ($withAggs) {
            $body['aggs'] = $this->buildAggregations($facetFilters);
        }

        $response = $this->es->search($this->index, $body);

        $result = $this->formatResponse($response, $page, $perPage, $withAggs);

        // "Did you mean" — only worth a second (cheap) call on zero results
        if ($result['total'] === 0 && trim($query) !== '') {
            $result['suggested_query'] = $this->didYouMean($query);
        }

        return $result;
    }

    public function findById(int $id, string $tenant = 'default'): array
    {
        $doc = $this->es->getDocument($this->index, $id);

        if (empty($doc)) {
            return [];
        }

        // Tenant isolation: never expose a document owned by another tenant
        if (config('elasticsearch.multi_tenancy') && ($doc['_source']['tenant_id'] ?? 'default') !== $tenant) {
            return [];
        }

        return array_merge(['id' => $doc['_id']], $doc['_source'] ?? []);
    }

    public function aggregate(array $params): array
    {
        $response = $this->es->search($this->index, array_merge(['size' => 0], $params));

        return $response['aggregations'] ?? [];
    }

    /**
     * Autocomplete: completion suggester first (in-memory FST, ~1ms, fuzzy),
     * falling back to typo-tolerant search-as-you-type when it finds little —
     * the completion field only matches from the start of an input, while
     * bool_prefix also matches mid-phrase words ("speaker bv").
     */
    public function suggest(string $prefix, string $tenant = 'default'): array
    {
        $prefix = trim($prefix);

        if ($prefix === '') {
            return [];
        }

        $tenancy = config('elasticsearch.multi_tenancy');

        $completion = [
            'field'           => 'suggest',
            'size'            => 10,
            'skip_duplicates' => true,
            'fuzzy'           => ['fuzziness' => 1],
        ];

        // Tenant context isolates suggestions — required once the completion
        // field declares a tenant context (after the multi-tenancy reindex)
        if ($tenancy) {
            $completion['contexts'] = ['tenant' => [$tenant]];
        }

        $response = $this->es->search($this->index, [
            '_source' => false,
            'suggest' => [
                'names' => [
                    'prefix'     => $prefix,
                    'completion' => $completion,
                ],
            ],
        ]);

        $names = array_map(
            fn (array $option) => $option['text'],
            $response['suggest']['names'][0]['options'] ?? []
        );

        if (count($names) >= 3) {
            return array_slice($names, 0, 10);
        }

        // Fallback: search-as-you-type across the n-gram subfields
        $multiMatch = [
            'multi_match' => [
                'query'     => $prefix,
                'type'      => 'bool_prefix',
                'fields'    => ['name.sayt', 'name.sayt._2gram', 'name.sayt._3gram'],
                'fuzziness' => 'AUTO',
            ],
        ];

        // Tenant-scope the fallback only when multi-tenancy is enabled
        $fallbackQuery = $tenancy
            ? ['bool' => ['must' => [$multiMatch], 'filter' => [['term' => ['tenant_id' => $tenant]]]]]
            : $multiMatch;

        $response = $this->es->search($this->index, [
            'size'    => 10,
            '_source' => ['name'],
            'query'   => $fallbackQuery,
        ]);

        $fallback = array_map(
            fn (array $hit) => $hit['_source']['name'],
            $response['hits']['hits'] ?? []
        );

        return array_slice(array_values(array_unique(array_merge($names, $fallback))), 0, 10);
    }

    // -------------------------------------------------------------------------
    // Query Builders
    // -------------------------------------------------------------------------

    /**
     * Facet selections grouped by facet, as ES filter clauses. Kept separate
     * from other filters because they go to post_filter and are selectively
     * excluded per-aggregation (multi-select faceting).
     */
    private function buildFacetFilters(array $filters): array
    {
        $facets = ['categories' => [], 'brands' => [], 'tags' => []];

        if (! empty($filters['category'])) {
            $facets['categories'][] = ['term' => ['category.keyword' => $filters['category']]];
        }
        if (! empty($filters['categories'])) {
            $facets['categories'][] = ['terms' => ['category.keyword' => (array) $filters['categories']]];
        }

        if (! empty($filters['brand'])) {
            $facets['brands'][] = ['term' => ['brand.keyword' => $filters['brand']]];
        }
        if (! empty($filters['brands'])) {
            $facets['brands'][] = ['terms' => ['brand.keyword' => (array) $filters['brands']]];
        }

        // Tags filter (AND — product must have ALL requested tags)
        if (! empty($filters['tags'])) {
            foreach ((array) $filters['tags'] as $tag) {
                $facets['tags'][] = ['term' => ['tags' => $tag]];
            }
        }

        return $facets;
    }

    /**
     * Build the bool query: must (relevance) + non-facet filters.
     *
     * Must clauses affect score; filter clauses do not (faster, cached by ES).
     * Facet selections are NOT here — they go to post_filter (see search()).
     */
    private function buildQuery(string $query, array $filters, array $options): array
    {
        $must   = [];
        $filter = [];
        $should = [];

        // ── Full-text search across EN and AZ analyzer chains ─────────────────
        if (! empty($query)) {
            // Synonyms (telefon→phone…) expand at search time inside the
            // en_search/az_search analyzers; fuzziness:AUTO handles typos
            $must[] = [
                'multi_match' => [
                    'query'     => $query,
                    'fields'    => ['name^3', 'name.az^3', 'brand^2', 'description', 'description.az', 'tags.text'],
                    'type'      => 'best_fields',
                    'fuzziness' => 'AUTO',
                    'operator'  => 'or',
                ],
            ];

            // Boost exact phrase matches in name to float them to the top
            $should[] = [
                'match_phrase' => [
                    'name' => ['query' => $query, 'boost' => 2],
                ],
            ];

            // Click-through popularity (rank_feature): additive, log-shaped —
            // popular products edge ahead without drowning text relevance.
            // Documents without the field simply contribute 0.
            $should[] = [
                'rank_feature' => ['field' => 'popularity', 'boost' => 0.5],
            ];
        } else {
            $must[] = ['match_all' => (object) []];
        }

        // ── Range filters ─────────────────────────────────────────────────────

        // Price range
        $priceRange = [];
        if (isset($filters['price_min'])) {
            $priceRange['gte'] = (float) $filters['price_min'];
        }
        if (isset($filters['price_max'])) {
            $priceRange['lte'] = (float) $filters['price_max'];
        }
        if ($priceRange) {
            $filter[] = ['range' => ['price' => $priceRange]];
        }

        // Date range (created_at)
        $dateRange = [];
        if (isset($options['from_date'])) {
            $dateRange['gte'] = $options['from_date'];
        }
        if (isset($options['to_date'])) {
            $dateRange['lte'] = $options['to_date'];
        }
        if ($dateRange) {
            $filter[] = ['range' => ['created_at' => $dateRange]];
        }

        // In-stock filter
        if (! empty($filters['in_stock'])) {
            $filter[] = ['range' => ['stock' => ['gt' => 0]]];
        }

        // Active products only (always applied — business rule; the index only
        // holds active products, this is belt-and-suspenders)
        $filter[] = ['term' => ['is_active' => true]];

        // Tenant isolation — every search is scoped to a single tenant. The
        // controller always supplies one (defaulting to config default_tenant),
        // so cross-tenant documents can never leak into results. Gated until the
        // index is reindexed with tenant_id (see config 'multi_tenancy').
        if (config('elasticsearch.multi_tenancy')) {
            $filter[] = ['term' => ['tenant_id' => $options['tenant'] ?? config('elasticsearch.default_tenant')]];
        }

        // ── Geo-distance filter ───────────────────────────────────────────────
        if (isset($options['geo_lat'], $options['geo_lon'], $options['geo_distance'])) {
            $filter[] = [
                'geo_distance' => [
                    'distance' => $options['geo_distance'],
                    'location' => [
                        'lat' => (float) $options['geo_lat'],
                        'lon' => (float) $options['geo_lon'],
                    ],
                ],
            ];
        }

        $boolQuery = [
            'bool' => array_filter([
                'must'   => $must,
                'filter' => $filter,
                'should' => $should ?: null,
            ]),
        ];

        // Business-rule scoring on top of text relevance: gentle in-stock
        // boost and a 90-day recency decay (multiplicative, so the ordering
        // within equal text relevance shifts without overriding it)
        return [
            'function_score' => [
                'query'      => $boolQuery,
                'functions'  => [
                    ['filter' => ['range' => ['stock' => ['gt' => 0]]], 'weight' => 1.1],
                    ['gauss' => ['created_at' => ['origin' => 'now', 'scale' => '90d', 'decay' => 0.5]]],
                ],
                'score_mode' => 'multiply',
                'boost_mode' => 'multiply',
            ],
        ];
    }

    /**
     * Build sort instructions. Every preset ends with the unique id —
     * search_after needs a fully deterministic order to avoid skipped or
     * duplicated hits at page boundaries.
     */
    private function buildSort(array $options): array
    {
        $sorts = [
            'relevance' => [['_score' => 'desc'], ['created_at' => 'desc']],
            'price_asc' => [['price' => 'asc']],
            'price_desc' => [['price' => 'desc']],
            'newest'    => [['created_at' => 'desc']],
            'oldest'    => [['created_at' => 'asc']],
            'name'      => [['name.keyword' => 'asc']],
            'stock_desc' => [['stock' => 'desc']],
        ];

        $sort = $options['sort'] ?? 'relevance';

        return array_merge($sorts[$sort] ?? $sorts['relevance'], [['id' => 'asc']]);
    }

    /**
     * Aggregation set for faceted navigation.
     *
     * Because facet selections live in post_filter, each facet aggregation
     * re-applies the OTHER facets' selections (not its own): selecting a
     * brand narrows category counts but leaves the brand list complete.
     * Metric aggs (price, stock) describe the fully-filtered result set.
     */
    private function buildAggregations(array $facetFilters): array
    {
        return [
            'categories' => $this->facetAgg(
                ['terms' => ['field' => 'category.keyword', 'size' => 20]],
                $facetFilters,
                'categories'
            ),
            'brands' => $this->facetAgg(
                ['terms' => ['field' => 'brand.keyword', 'size' => 30]],
                $facetFilters,
                'brands'
            ),
            'tags_cloud' => $this->facetAgg(
                ['terms' => ['field' => 'tags', 'size' => 50]],
                $facetFilters,
                'tags'
            ),

            // Range aggregation — price buckets for filter UI
            'price_ranges' => $this->facetAgg(
                [
                    'range' => [
                        'field'  => 'price',
                        'ranges' => [
                            ['key' => 'under_50',        'to'   => 50],
                            ['key' => '50_to_200',        'from' => 50,   'to'  => 200],
                            ['key' => '200_to_500',       'from' => 200,  'to'  => 500],
                            ['key' => '500_to_1000',      'from' => 500,  'to'  => 1000],
                            ['key' => 'over_1000',        'from' => 1000],
                        ],
                    ],
                ],
                $facetFilters,
                null
            ),

            // Metric aggregations — price statistics
            'avg_price' => $this->facetAgg(['avg' => ['field' => 'price']], $facetFilters, null),
            'max_price' => $this->facetAgg(['max' => ['field' => 'price']], $facetFilters, null),
            'min_price' => $this->facetAgg(['min' => ['field' => 'price']], $facetFilters, null),

            // Sum aggregation — total inventory
            'total_stock' => $this->facetAgg(['sum' => ['field' => 'stock']], $facetFilters, null),

            // Cardinality — unique brand count
            'unique_brands' => $this->facetAgg(['cardinality' => ['field' => 'brand.keyword']], $facetFilters, null),
        ];
    }

    /**
     * Wrap an aggregation with the facet selections it must respect — all
     * facets except $exclude (its own). With nothing to apply, the agg is
     * returned unwrapped, keeping the response shape flat.
     */
    private function facetAgg(array $agg, array $facetFilters, ?string $exclude): array
    {
        $clauses = [];
        foreach ($facetFilters as $facet => $facetClauses) {
            if ($facet !== $exclude) {
                $clauses = array_merge($clauses, $facetClauses);
            }
        }

        if (! $clauses) {
            return $agg;
        }

        return [
            'filter' => ['bool' => ['filter' => $clauses]],
            'aggs'   => ['filtered' => $agg],
        ];
    }

    /**
     * Highlight configuration: wraps matched terms with <em> tags.
     * require_field_match=false lets a hit on name.az still highlight name.
     */
    private function buildHighlight(): array
    {
        return [
            'pre_tags'  => ['<em>'],
            'post_tags' => ['</em>'],
            'require_field_match' => false,
            'fields'    => [
                'name'        => ['number_of_fragments' => 0],
                'description' => ['number_of_fragments' => 2, 'fragment_size' => 150],
            ],
        ];
    }

    /**
     * Phrase-suggester "did you mean" on the un-stemmed name.dym subfield.
     * Returns the best correction, or null when ES has none.
     */
    private function didYouMean(string $query): ?string
    {
        $response = $this->es->search($this->index, [
            'size'    => 0,
            'suggest' => [
                'dym' => [
                    'text'   => $query,
                    'phrase' => [
                        'field'            => 'name.dym',
                        'size'             => 1,
                        'direct_generator' => [
                            ['field' => 'name.dym', 'suggest_mode' => 'always', 'min_word_length' => 3],
                        ],
                    ],
                ],
            ],
        ]);

        return $response['suggest']['dym'][0]['options'][0]['text'] ?? null;
    }

    // -------------------------------------------------------------------------
    // Response Formatting
    // -------------------------------------------------------------------------

    /**
     * Shape the raw Elasticsearch response into a structured, frontend-friendly array.
     * Merges highlight fragments into each hit's source data.
     */
    private function formatResponse(array $response, int $page, int $perPage, bool $withAggs = true): array
    {
        $hits    = $response['hits']['hits'] ?? [];
        $total   = $response['hits']['total']['value'] ?? 0;
        $lastPage = $perPage > 0 ? (int) ceil($total / $perPage) : 1;

        $data = array_map(function (array $hit) {
            $source    = $hit['_source'] ?? [];
            $highlight = $hit['highlight'] ?? [];

            // Internal-only fields shouldn't leak to API consumers
            unset($source['suggest'], $source['tenant_id']);

            // Merge highlights into the source so callers see highlighted fields
            foreach ($highlight as $field => $fragments) {
                $source["highlighted_{$field}"] = implode(' ... ', $fragments);
            }

            return array_merge(['_score' => $hit['_score'] ?? null], $source);
        }, $hits);

        // Opaque cursor for search_after pagination: the sort values of the
        // last hit on this page. null when the result set is exhausted.
        $lastSort   = count($hits) === $perPage ? (end($hits)['sort'] ?? null) : null;
        $nextCursor = $lastSort ? base64_encode(json_encode($lastSort)) : null;

        return [
            'data'         => $data,
            'total'        => $total,
            // false when ES stopped counting at track_total_hits (relation "gte")
            'total_is_exact' => ($response['hits']['total']['relation'] ?? 'eq') === 'eq',
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => $lastPage,
            'next_cursor'  => $nextCursor,
            'aggregations' => $withAggs ? $this->formatAggregations($response['aggregations'] ?? []) : null,
            'took_ms'      => $response['took'] ?? 0,
            'max_score'    => $response['hits']['max_score'] ?? null,
        ];
    }

    /**
     * Reshape raw ES aggregations into cleaner structures for API consumers.
     */
    private function formatAggregations(array $aggs): array
    {
        return [
            'categories' => array_map(
                fn ($b) => ['name' => $b['key'], 'count' => $b['doc_count']],
                $this->aggNode($aggs, 'categories')['buckets'] ?? []
            ),
            'brands' => array_map(
                fn ($b) => ['name' => $b['key'], 'count' => $b['doc_count']],
                $this->aggNode($aggs, 'brands')['buckets'] ?? []
            ),
            'tags' => array_map(
                fn ($b) => ['tag' => $b['key'], 'count' => $b['doc_count']],
                $this->aggNode($aggs, 'tags_cloud')['buckets'] ?? []
            ),
            'price_ranges' => array_map(
                fn ($b) => [
                    'label' => $b['key'],
                    'from'  => $b['from'] ?? null,
                    'to'    => $b['to'] ?? null,
                    'count' => $b['doc_count'],
                ],
                $this->aggNode($aggs, 'price_ranges')['buckets'] ?? []
            ),
            'price_stats' => [
                'avg' => round($this->aggNode($aggs, 'avg_price')['value'] ?? 0, 2),
                'min' => round($this->aggNode($aggs, 'min_price')['value'] ?? 0, 2),
                'max' => round($this->aggNode($aggs, 'max_price')['value'] ?? 0, 2),
            ],
            'total_stock'   => (int) ($this->aggNode($aggs, 'total_stock')['value'] ?? 0),
            'unique_brands' => (int) ($this->aggNode($aggs, 'unique_brands')['value'] ?? 0),
        ];
    }

    /**
     * Resolve an aggregation node whether it's flat or wrapped in a facet
     * filter (see facetAgg — wrapped nodes nest the real agg under 'filtered').
     */
    private function aggNode(array $aggs, string $name): array
    {
        $node = $aggs[$name] ?? [];

        return $node['filtered'] ?? $node;
    }
}
