<?php

namespace Tests\Unit;

use App\Services\ElasticsearchService;
use App\Services\Repositories\ProductSearchRepository;
use Mockery;
use Tests\TestCase;

class ProductSearchRepositoryTest extends TestCase
{
    private ProductSearchRepository $repository;
    private ElasticsearchService $mockEs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockEs     = Mockery::mock(ElasticsearchService::class);
        $this->repository = new ProductSearchRepository($this->mockEs);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * The text query lives inside a function_score wrapper (recency decay +
     * in-stock boost) — unwrap to the bool part for assertions.
     */
    private function boolQuery(array $body): array
    {
        $this->assertArrayHasKey('function_score', $body['query']);

        return $body['query']['function_score']['query']['bool'];
    }

    public function test_search_sends_match_all_when_query_is_empty(): void
    {
        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->with('products_test', Mockery::on(function ($body) {
                $must = $this->boolQuery($body)['must'];
                $this->assertCount(1, $must);
                $this->assertArrayHasKey('match_all', $must[0]);

                return true;
            }))
            ->andReturn($this->makeSearchResponse());

        $result = $this->repository->search('');

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('aggregations', $result);
    }

    public function test_function_score_applies_recency_and_stock_boosts(): void
    {
        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->with('products_test', Mockery::on(function ($body) {
                $fs = $body['query']['function_score'];
                $this->assertEquals('multiply', $fs['boost_mode']);

                $functions = collect($fs['functions']);
                $this->assertNotNull($functions->first(fn ($f) => isset($f['gauss']['created_at'])));
                $this->assertNotNull($functions->first(fn ($f) => isset($f['filter']['range']['stock']) && $f['weight'] === 1.1));

                return true;
            }))
            ->andReturn($this->makeSearchResponse([$this->makeHit(1)], 1));

        $this->repository->search('samsung');
    }

    public function test_text_query_adds_popularity_rank_feature_boost(): void
    {
        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->with('products_test', Mockery::on(function ($body) {
                $should = $this->boolQuery($body)['should'];
                $rankFeature = collect($should)->first(fn ($s) => isset($s['rank_feature']));
                $this->assertNotNull($rankFeature);
                $this->assertEquals('popularity', $rankFeature['rank_feature']['field']);

                return true;
            }))
            ->andReturn($this->makeSearchResponse([$this->makeHit(1)], 1));

        $this->repository->search('samsung');
    }

    public function test_search_builds_multi_match_for_text_query(): void
    {
        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->with('products_test', Mockery::on(function ($body) {
                $must = $this->boolQuery($body)['must'];
                $this->assertCount(1, $must);
                $this->assertArrayHasKey('multi_match', $must[0]);
                $this->assertEquals('samsung', $must[0]['multi_match']['query']);
                $this->assertContains('name^3', $must[0]['multi_match']['fields']);
                // Azerbaijani subfields and tags participate in relevance
                $this->assertContains('name.az^3', $must[0]['multi_match']['fields']);
                $this->assertContains('tags.text', $must[0]['multi_match']['fields']);
                $this->assertEquals('AUTO', $must[0]['multi_match']['fuzziness']);

                return true;
            }))
            ->andReturn($this->makeSearchResponse([$this->makeHit(1)], 1));

        $this->repository->search('samsung');
    }

    public function test_search_puts_category_selection_in_post_filter(): void
    {
        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->with('products_test', Mockery::on(function ($body) {
                // Facet selections live in post_filter (multi-select faceting)…
                $postFilter = $body['post_filter']['bool']['filter'] ?? [];
                $categoryFilter = collect($postFilter)->first(
                    fn ($f) => isset($f['term']['category.keyword']) && $f['term']['category.keyword'] === 'electronics'
                );
                $this->assertNotNull($categoryFilter, 'category.keyword post_filter not found');

                // …and must NOT leak into the query filter (that would collapse
                // the category aggregation to the selected value)
                $queryFilter = $this->boolQuery($body)['filter'] ?? [];
                $this->assertNull(collect($queryFilter)->first(
                    fn ($f) => isset($f['term']['category.keyword'])
                ));

                return true;
            }))
            ->andReturn($this->makeSearchResponse());

        $this->repository->search('', ['category' => 'electronics']);
    }

    public function test_facet_aggregations_exclude_own_selection(): void
    {
        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->with('products_test', Mockery::on(function ($body) {
                $aggs = $body['aggs'];

                // The category agg ignores the category selection → stays flat
                $this->assertArrayHasKey('terms', $aggs['categories']);

                // Other facets and metrics must respect it → filter-wrapped
                foreach (['brands', 'tags_cloud', 'avg_price', 'price_ranges'] as $name) {
                    $this->assertArrayHasKey('filter', $aggs[$name], "{$name} should be wrapped");
                    $wrap = collect($aggs[$name]['filter']['bool']['filter'])->first(
                        fn ($f) => isset($f['term']['category.keyword'])
                    );
                    $this->assertNotNull($wrap, "{$name} wrap must contain the category selection");
                    $this->assertArrayHasKey('filtered', $aggs[$name]['aggs']);
                }

                return true;
            }))
            ->andReturn($this->makeSearchResponse());

        $this->repository->search('', ['category' => 'electronics']);
    }

    public function test_with_aggs_false_omits_aggregations(): void
    {
        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->with('products_test', Mockery::on(function ($body) {
                $this->assertArrayNotHasKey('aggs', $body);

                return true;
            }))
            ->andReturn($this->makeSearchResponse());

        $result = $this->repository->search('', [], ['with_aggs' => false]);

        $this->assertNull($result['aggregations']);
    }

    public function test_zero_results_trigger_did_you_mean(): void
    {
        // First call: the actual search — no hits
        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->with('products_test', Mockery::on(fn ($body) => isset($body['query'])))
            ->andReturn($this->makeSearchResponse());

        // Second call: phrase suggester on the un-stemmed name.dym subfield
        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->with('products_test', Mockery::on(function ($body) {
                return isset($body['suggest']['dym'])
                    && $body['suggest']['dym']['phrase']['field'] === 'name.dym'
                    && $body['suggest']['dym']['text'] === 'samsng';
            }))
            ->andReturn([
                'suggest' => ['dym' => [['options' => [['text' => 'samsung']]]]],
            ]);

        $result = $this->repository->search('samsng');

        $this->assertEquals('samsung', $result['suggested_query']);
    }

    public function test_search_adds_price_range_filter(): void
    {
        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->with('products_test', Mockery::on(function ($body) {
                $filter = $this->boolQuery($body)['filter'];

                $priceFilter = collect($filter)->first(fn ($f) => isset($f['range']['price']));
                $this->assertNotNull($priceFilter);
                $this->assertEquals(100.0, $priceFilter['range']['price']['gte']);
                $this->assertEquals(500.0, $priceFilter['range']['price']['lte']);

                return true;
            }))
            ->andReturn($this->makeSearchResponse());

        $this->repository->search('', ['price_min' => 100, 'price_max' => 500]);
    }

    public function test_search_adds_in_stock_filter(): void
    {
        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->with('products_test', Mockery::on(function ($body) {
                $filter = $this->boolQuery($body)['filter'];

                $stockFilter = collect($filter)->first(fn ($f) => isset($f['range']['stock']));
                $this->assertNotNull($stockFilter);
                $this->assertEquals(0, $stockFilter['range']['stock']['gt']);

                return true;
            }))
            ->andReturn($this->makeSearchResponse());

        $this->repository->search('', ['in_stock' => true]);
    }

    public function test_search_sets_pagination_correctly(): void
    {
        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->with('products_test', Mockery::on(function ($body) {
                // Page 3 with 10 per page = from 20
                $this->assertEquals(20, $body['from']);
                $this->assertEquals(10, $body['size']);

                return true;
            }))
            ->andReturn($this->makeSearchResponse());

        $this->repository->search('', [], ['page' => 3, 'per_page' => 10]);
    }

    public function test_search_applies_price_asc_sort(): void
    {
        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->with('products_test', Mockery::on(function ($body) {
                $this->assertEquals([['price' => 'asc'], ['id' => 'asc']], $body['sort']);

                return true;
            }))
            ->andReturn($this->makeSearchResponse());

        $this->repository->search('', [], ['sort' => 'price_asc']);
    }

    public function test_search_includes_aggregations(): void
    {
        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->with('products_test', Mockery::on(function ($body) {
                $this->assertArrayHasKey('aggs', $body);
                $this->assertArrayHasKey('categories', $body['aggs']);
                $this->assertArrayHasKey('brands', $body['aggs']);
                $this->assertArrayHasKey('price_ranges', $body['aggs']);
                $this->assertArrayHasKey('avg_price', $body['aggs']);
                $this->assertArrayHasKey('total_stock', $body['aggs']);

                return true;
            }))
            ->andReturn($this->makeSearchResponse());

        $this->repository->search('');
    }

    public function test_search_includes_highlight_config(): void
    {
        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->with('products_test', Mockery::on(function ($body) {
                $this->assertArrayHasKey('highlight', $body);
                $this->assertArrayHasKey('name', $body['highlight']['fields']);
                $this->assertArrayHasKey('description', $body['highlight']['fields']);
                $this->assertEquals(['<em>'], $body['highlight']['pre_tags']);
                // A hit on name.az must still highlight the base name field
                $this->assertFalse($body['highlight']['require_field_match']);

                return true;
            }))
            ->andReturn($this->makeSearchResponse([$this->makeHit(1)], 1));

        $this->repository->search('laptop');
    }

    public function test_suggest_uses_completion_suggester(): void
    {
        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->with('products_test', Mockery::on(function ($body) {
                $completion = $body['suggest']['names']['completion'];
                $this->assertEquals('suggest', $completion['field']);
                $this->assertTrue($completion['skip_duplicates']);
                $this->assertEquals(1, $completion['fuzzy']['fuzziness']);
                // Tenant context isolates suggestions
                $this->assertEquals(['default'], $completion['contexts']['tenant']);

                return true;
            }))
            ->andReturn([
                'suggest' => ['names' => [['options' => [
                    ['text' => 'Samsung Smartphone'],
                    ['text' => 'Samsung Tablet'],
                    ['text' => 'Samsung TV'],
                ]]]],
            ]);

        $result = $this->repository->suggest('sam');

        $this->assertEquals(['Samsung Smartphone', 'Samsung Tablet', 'Samsung TV'], $result);
    }

    public function test_suggest_falls_back_to_search_as_you_type(): void
    {
        // Completion finds too little (mid-phrase prefixes don't match an FST)
        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->with('products_test', Mockery::on(fn ($body) => isset($body['suggest']['names'])))
            ->andReturn(['suggest' => ['names' => [['options' => [['text' => 'Speaker One']]]]]]);

        // Fallback: bool_prefix across the search_as_you_type n-gram fields
        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->with('products_test', Mockery::on(function ($body) {
                $mm = $body['query']['bool']['must'][0]['multi_match'];
                $this->assertEquals('bool_prefix', $mm['type']);
                $this->assertContains('name.sayt', $mm['fields']);
                $this->assertContains('name.sayt._2gram', $mm['fields']);
                // Fallback is tenant-scoped too
                $this->assertEquals(['term' => ['tenant_id' => 'default']], $body['query']['bool']['filter'][0]);

                return true;
            }))
            ->andReturn(['hits' => ['hits' => [
                ['_source' => ['name' => 'Speaker One']],
                ['_source' => ['name' => 'Sony Speaker Two']],
            ]]]);

        $result = $this->repository->suggest('speaker o');

        // Merged and de-duplicated, completion results first
        $this->assertEquals(['Speaker One', 'Sony Speaker Two'], $result);
    }

    public function test_suggest_returns_empty_array_for_empty_prefix(): void
    {
        $this->mockEs->shouldNotReceive('search');

        $result = $this->repository->suggest('');

        $this->assertEmpty($result);
    }

    public function test_format_response_calculates_last_page(): void
    {
        $hits = [$this->makeHit(1), $this->makeHit(2)];

        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->andReturn($this->makeSearchResponse($hits, 47));

        $result = $this->repository->search('', [], ['per_page' => 15, 'page' => 1]);

        $this->assertEquals(47, $result['total']);
        $this->assertEquals(4, $result['last_page']); // ceil(47/15) = 4
    }

    public function test_format_response_merges_highlights(): void
    {
        $hit               = $this->makeHit(1);
        $hit['highlight']  = ['name' => ['<em>Test</em> Product 1']];

        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->andReturn($this->makeSearchResponse([$hit], 1));

        $result = $this->repository->search('Test');

        $this->assertArrayHasKey('highlighted_name', $result['data'][0]);
        $this->assertStringContainsString('<em>Test</em>', $result['data'][0]['highlighted_name']);
    }

    public function test_search_sets_track_total_hits_cap(): void
    {
        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->with('products_test', Mockery::on(function ($body) {
                $this->assertSame(10000, $body['track_total_hits']);

                return true;
            }))
            ->andReturn($this->makeSearchResponse([$this->makeHit(1)], 1));

        $this->repository->search('anything');
    }

    public function test_total_is_exact_reflects_total_relation(): void
    {
        $exact = $this->makeSearchResponse([$this->makeHit(1)], 1);

        $capped = $this->makeSearchResponse([$this->makeHit(2)], 10000);
        $capped['hits']['total']['relation'] = 'gte'; // ES stopped counting

        $this->mockEs->shouldReceive('search')->twice()->andReturn($exact, $capped);

        $this->assertTrue($this->repository->search('first')['total_is_exact']);
        $this->assertFalse($this->repository->search('second')['total_is_exact']);
    }

    // ── Multi-tenancy ─────────────────────────────────────────────────────────

    public function test_search_filters_by_tenant(): void
    {
        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->with('products_test', Mockery::on(function ($body) {
                $filter = $this->boolQuery($body)['filter'];
                $tenantClause = collect($filter)->first(fn ($f) => isset($f['term']['tenant_id']));
                $this->assertNotNull($tenantClause, 'tenant_id filter missing');
                $this->assertSame('acme', $tenantClause['term']['tenant_id']);

                return true;
            }))
            ->andReturn($this->makeSearchResponse([$this->makeHit(1)], 1));

        $this->repository->search('phone', [], ['tenant' => 'acme']);
    }

    public function test_no_tenant_filter_when_multi_tenancy_disabled(): void
    {
        // Safety gate: with the flag off (pre-migration), searches must NOT add
        // the tenant_id filter — otherwise every result would be filtered away
        config(['elasticsearch.multi_tenancy' => false]);

        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->with('products_test', Mockery::on(function ($body) {
                $filter = $this->boolQuery($body)['filter'];
                $this->assertNull(collect($filter)->first(fn ($f) => isset($f['term']['tenant_id'])));

                return true;
            }))
            ->andReturn($this->makeSearchResponse([$this->makeHit(1)], 1));

        $this->repository->search('phone', [], ['tenant' => 'acme']);
    }

    public function test_find_by_id_hides_other_tenant_document(): void
    {
        $this->mockEs
            ->shouldReceive('getDocument')
            ->andReturn(['_id' => '1', '_source' => ['id' => 1, 'tenant_id' => 'other', 'name' => 'Foreign']]);

        // Requested as 'acme' → not visible
        $this->assertSame([], $this->repository->findById(1, 'acme'));
        // Requested as the owning tenant → visible
        $this->assertSame('Foreign', $this->repository->findById(1, 'other')['name']);
    }
}
