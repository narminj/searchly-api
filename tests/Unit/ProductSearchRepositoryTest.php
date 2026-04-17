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

    public function test_search_sends_match_all_when_query_is_empty(): void
    {
        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->with('products_test', Mockery::on(function ($body) {
                $query = $body['query'];
                $this->assertArrayHasKey('bool', $query);
                $must = $query['bool']['must'];
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

    public function test_search_builds_multi_match_for_text_query(): void
    {
        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->with('products_test', Mockery::on(function ($body) {
                $must = $body['query']['bool']['must'];
                $this->assertCount(1, $must);
                $this->assertArrayHasKey('multi_match', $must[0]);
                $this->assertEquals('samsung', $must[0]['multi_match']['query']);
                $this->assertContains('name^3', $must[0]['multi_match']['fields']);
                $this->assertEquals('AUTO', $must[0]['multi_match']['fuzziness']);

                return true;
            }))
            ->andReturn($this->makeSearchResponse());

        $this->repository->search('samsung');
    }

    public function test_search_adds_category_term_filter(): void
    {
        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->with('products_test', Mockery::on(function ($body) {
                $filter = $body['query']['bool']['filter'];

                // 'category.keyword' is a literal key with a dot — use first() with closure
                $categoryFilter = collect($filter)->first(
                    fn ($f) => isset($f['term']['category.keyword']) && $f['term']['category.keyword'] === 'electronics'
                );
                $this->assertNotNull($categoryFilter, 'category.keyword filter not found');

                return true;
            }))
            ->andReturn($this->makeSearchResponse());

        $this->repository->search('', ['category' => 'electronics']);
    }

    public function test_search_adds_price_range_filter(): void
    {
        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->with('products_test', Mockery::on(function ($body) {
                $filter = $body['query']['bool']['filter'];

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
                $filter = $body['query']['bool']['filter'];

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
                $this->assertEquals([['price' => 'asc']], $body['sort']);

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

                return true;
            }))
            ->andReturn($this->makeSearchResponse());

        $this->repository->search('laptop');
    }

    public function test_suggest_returns_product_names(): void
    {
        $responseHits = [
            ['_source' => ['name' => 'Samsung Smartphone']],
            ['_source' => ['name' => 'Samsung Tablet']],
        ];

        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->with('products_test', Mockery::on(function ($body) {
                // Autocomplete uses name.autocomplete sub-field
                $this->assertArrayHasKey('match', $body['query']);
                $this->assertArrayHasKey('name.autocomplete', $body['query']['match']);

                return true;
            }))
            ->andReturn([
                'hits' => ['hits' => $responseHits],
            ]);

        $result = $this->repository->suggest('sam');

        $this->assertEquals(['Samsung Smartphone', 'Samsung Tablet'], $result);
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
}
