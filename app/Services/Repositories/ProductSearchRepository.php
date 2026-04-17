<?php

namespace App\Services\Repositories;

use App\Contracts\SearchRepositoryInterface;
use App\Services\ElasticsearchService;

/**
 * Product-specific Elasticsearch query builder.
 *
 * Demonstrates: full-text search, fuzzy, term/terms filters, range queries,
 * bool queries (must/filter/should/must_not), aggregations (terms, range,
 * avg/min/max/sum), highlighting, pagination, sorting, geo-distance, autocomplete.
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
     *   page, per_page, from_date, to_date,
     *   geo_lat, geo_lon, geo_distance (e.g. "50km")
     */
    public function search(string $query, array $filters = [], array $options = []): array
    {
        $perPage = (int) ($options['per_page'] ?? 15);
        $page    = (int) ($options['page'] ?? 1);
        $from    = ($page - 1) * $perPage;

        $body = [
            'from'      => $from,
            'size'      => $perPage,
            'query'     => $this->buildQuery($query, $filters, $options),
            'sort'      => $this->buildSort($options),
            'aggs'      => $this->buildAggregations(),
            'highlight' => $this->buildHighlight(),
        ];

        $response = $this->es->search($this->index, $body);

        return $this->formatResponse($response, $page, $perPage);
    }

    public function findById(int $id): array
    {
        $doc = $this->es->getDocument($this->index, $id);

        if (empty($doc)) {
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
     * Edge n-gram prefix autocomplete using the name.autocomplete sub-field.
     * Returns top 10 matching product names for dropdown suggestions.
     */
    public function suggest(string $prefix): array
    {
        if (empty(trim($prefix))) {
            return [];
        }

        $body = [
            'size'    => 10,
            '_source' => ['name'],
            'query'   => [
                'match' => [
                    'name.autocomplete' => [
                        'query'    => $prefix,
                        'operator' => 'and',
                    ],
                ],
            ],
        ];

        $response = $this->es->search($this->index, $body);

        return array_map(
            fn ($hit) => $hit['_source']['name'],
            $response['hits']['hits'] ?? []
        );
    }

    // -------------------------------------------------------------------------
    // Query Builders
    // -------------------------------------------------------------------------

    /**
     * Build the bool query combining must (relevance) + filter (exact) clauses.
     *
     * Must clauses affect score; filter clauses do not (faster, cached by ES).
     * Always-active filters go in filter context, not must.
     */
    private function buildQuery(string $query, array $filters, array $options): array
    {
        $must   = [];
        $filter = [];
        $should = [];

        // ── Full-text search ──────────────────────────────────────────────────
        if (! empty($query)) {
            // multi_match across name (boosted 3x), brand (2x), description, tags
            // fuzziness:AUTO handles typos: 0 edits for ≤2 chars, 1 for 3-5, 2 for 6+
            $must[] = [
                'multi_match' => [
                    'query'     => $query,
                    'fields'    => ['name^3', 'brand^2', 'description', 'tags'],
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
        } else {
            $must[] = ['match_all' => (object) []];
        }

        // ── Term filters (exact match — use .keyword sub-field) ───────────────

        // Single category
        if (! empty($filters['category'])) {
            $filter[] = ['term' => ['category.keyword' => $filters['category']]];
        }

        // Multiple categories (OR)
        if (! empty($filters['categories'])) {
            $filter[] = ['terms' => ['category.keyword' => (array) $filters['categories']]];
        }

        // Single brand
        if (! empty($filters['brand'])) {
            $filter[] = ['term' => ['brand.keyword' => $filters['brand']]];
        }

        // Multiple brands (OR)
        if (! empty($filters['brands'])) {
            $filter[] = ['terms' => ['brand.keyword' => (array) $filters['brands']]];
        }

        // Tags filter (AND — product must have ALL requested tags)
        if (! empty($filters['tags'])) {
            foreach ((array) $filters['tags'] as $tag) {
                $filter[] = ['term' => ['tags' => $tag]];
            }
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

        // Active products only (always applied — business rule)
        $filter[] = ['term' => ['is_active' => true]];

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

        return [
            'bool' => array_filter([
                'must'   => $must,
                'filter' => $filter,
                'should' => $should ?: null,
            ]),
        ];
    }

    /**
     * Build sort instructions.
     * Defaults to relevance (_score desc) when a query string is present,
     * otherwise newest first.
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

        return $sorts[$sort] ?? $sorts['relevance'];
    }

    /**
     * Standard aggregation set returned on every search response.
     * Provides facet data for category/brand filters and price stats.
     */
    private function buildAggregations(): array
    {
        return [
            // Terms aggregations — for faceted navigation
            'categories' => [
                'terms' => ['field' => 'category.keyword', 'size' => 20],
            ],
            'brands' => [
                'terms' => ['field' => 'brand.keyword', 'size' => 30],
            ],
            'tags_cloud' => [
                'terms' => ['field' => 'tags', 'size' => 50],
            ],

            // Range aggregation — price buckets for filter UI
            'price_ranges' => [
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

            // Metric aggregations — price statistics
            'avg_price'   => ['avg' => ['field' => 'price']],
            'max_price'   => ['max' => ['field' => 'price']],
            'min_price'   => ['min' => ['field' => 'price']],

            // Sum aggregation — total inventory
            'total_stock' => ['sum' => ['field' => 'stock']],

            // Cardinality — unique brand count
            'unique_brands' => ['cardinality' => ['field' => 'brand.keyword']],
        ];
    }

    /**
     * Highlight configuration: wraps matched terms with <em> tags.
     * name returns the full field (no fragmentation).
     * description returns up to 2 fragments of 150 chars each.
     */
    private function buildHighlight(): array
    {
        return [
            'pre_tags'  => ['<em>'],
            'post_tags' => ['</em>'],
            'fields'    => [
                'name'        => ['number_of_fragments' => 0],
                'description' => ['number_of_fragments' => 2, 'fragment_size' => 150],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Response Formatting
    // -------------------------------------------------------------------------

    /**
     * Shape the raw Elasticsearch response into a structured, frontend-friendly array.
     * Merges highlight fragments into each hit's source data.
     */
    private function formatResponse(array $response, int $page, int $perPage): array
    {
        $hits    = $response['hits']['hits'] ?? [];
        $total   = $response['hits']['total']['value'] ?? 0;
        $lastPage = $perPage > 0 ? (int) ceil($total / $perPage) : 1;

        $data = array_map(function (array $hit) {
            $source    = $hit['_source'] ?? [];
            $highlight = $hit['highlight'] ?? [];

            // Merge highlights into the source so callers see highlighted fields
            foreach ($highlight as $field => $fragments) {
                $source["highlighted_{$field}"] = implode(' ... ', $fragments);
            }

            return array_merge(['_score' => $hit['_score'] ?? null], $source);
        }, $hits);

        return [
            'data'         => $data,
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => $lastPage,
            'aggregations' => $this->formatAggregations($response['aggregations'] ?? []),
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
                $aggs['categories']['buckets'] ?? []
            ),
            'brands' => array_map(
                fn ($b) => ['name' => $b['key'], 'count' => $b['doc_count']],
                $aggs['brands']['buckets'] ?? []
            ),
            'tags' => array_map(
                fn ($b) => ['tag' => $b['key'], 'count' => $b['doc_count']],
                $aggs['tags_cloud']['buckets'] ?? []
            ),
            'price_ranges' => array_map(
                fn ($b) => [
                    'label' => $b['key'],
                    'from'  => $b['from'] ?? null,
                    'to'    => $b['to'] ?? null,
                    'count' => $b['doc_count'],
                ],
                $aggs['price_ranges']['buckets'] ?? []
            ),
            'price_stats' => [
                'avg' => round($aggs['avg_price']['value'] ?? 0, 2),
                'min' => round($aggs['min_price']['value'] ?? 0, 2),
                'max' => round($aggs['max_price']['value'] ?? 0, 2),
            ],
            'total_stock'   => (int) ($aggs['total_stock']['value'] ?? 0),
            'unique_brands' => (int) ($aggs['unique_brands']['value'] ?? 0),
        ];
    }
}
