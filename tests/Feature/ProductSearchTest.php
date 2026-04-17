<?php

namespace Tests\Feature;

use App\Contracts\SearchRepositoryInterface;
use App\Services\ElasticsearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProductSearchTest extends TestCase
{
    use RefreshDatabase;

    private SearchRepositoryInterface $mockRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockRepository = Mockery::mock(SearchRepositoryInterface::class);
        $this->app->instance(SearchRepositoryInterface::class, $this->mockRepository);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeSearchResult(array $overrides = []): array
    {
        return array_merge([
            'data'         => [],
            'total'        => 0,
            'per_page'     => 15,
            'current_page' => 1,
            'last_page'    => 1,
            'aggregations' => [
                'categories'   => [],
                'brands'       => [],
                'tags'         => [],
                'price_ranges' => [],
                'price_stats'  => ['avg' => 0, 'min' => 0, 'max' => 0],
                'total_stock'  => 0,
                'unique_brands' => 0,
            ],
            'took_ms'   => 1,
            'max_score' => null,
        ], $overrides);
    }

    // ── Search endpoint ───────────────────────────────────────────────────────

    public function test_search_endpoint_returns_200(): void
    {
        $this->mockRepository
            ->shouldReceive('search')
            ->once()
            ->andReturn($this->makeSearchResult());

        $response = $this->getJson('/api/products/search');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'total', 'per_page', 'current_page', 'last_page', 'aggregations']);
    }

    public function test_search_endpoint_passes_query_to_repository(): void
    {
        $this->mockRepository
            ->shouldReceive('search')
            ->once()
            ->with('samsung', Mockery::any(), Mockery::any())
            ->andReturn($this->makeSearchResult());

        $this->getJson('/api/products/search?q=samsung')->assertStatus(200);
    }

    public function test_search_endpoint_passes_category_filter(): void
    {
        $this->mockRepository
            ->shouldReceive('search')
            ->once()
            ->with(
                Mockery::any(),
                Mockery::on(fn ($f) => ($f['category'] ?? null) === 'electronics'),
                Mockery::any()
            )
            ->andReturn($this->makeSearchResult());

        $this->getJson('/api/products/search?category=electronics')->assertStatus(200);
    }

    public function test_search_endpoint_passes_price_filters(): void
    {
        $this->mockRepository
            ->shouldReceive('search')
            ->once()
            ->with(
                Mockery::any(),
                Mockery::on(fn ($f) => ($f['price_min'] ?? null) == 100 && ($f['price_max'] ?? null) == 500),
                Mockery::any()
            )
            ->andReturn($this->makeSearchResult());

        $this->getJson('/api/products/search?price_min=100&price_max=500')->assertStatus(200);
    }

    public function test_search_endpoint_validates_sort_values(): void
    {
        $this->mockRepository->shouldNotReceive('search');

        $this->getJson('/api/products/search?sort=invalid_sort')->assertStatus(422);
    }

    public function test_search_endpoint_validates_per_page_max(): void
    {
        $this->mockRepository->shouldNotReceive('search');

        $this->getJson('/api/products/search?per_page=999')->assertStatus(422);
    }

    public function test_search_endpoint_returns_products_in_data(): void
    {
        $products = [
            ['id' => 1, 'name' => 'Samsung Galaxy', 'price' => 799.99, '_score' => 2.5],
            ['id' => 2, 'name' => 'Apple iPhone',   'price' => 999.99, '_score' => 2.1],
        ];

        $this->mockRepository
            ->shouldReceive('search')
            ->once()
            ->andReturn($this->makeSearchResult(['data' => $products, 'total' => 2]));

        $response = $this->getJson('/api/products/search?q=phone');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 2);
    }

    public function test_search_endpoint_returns_aggregations(): void
    {
        $aggs = [
            'categories'  => [['name' => 'electronics', 'count' => 50]],
            'brands'      => [['name' => 'Samsung', 'count' => 20]],
            'tags'        => [],
            'price_ranges' => [],
            'price_stats' => ['avg' => 299.99, 'min' => 9.99, 'max' => 2499.99],
            'total_stock' => 1500,
            'unique_brands' => 10,
        ];

        $this->mockRepository
            ->shouldReceive('search')
            ->once()
            ->andReturn($this->makeSearchResult(['aggregations' => $aggs]));

        $response = $this->getJson('/api/products/search');

        $response->assertStatus(200)
            ->assertJsonPath('aggregations.categories.0.name', 'electronics')
            ->assertJsonPath('aggregations.price_stats.avg', 299.99);
    }

    // ── Show endpoint ─────────────────────────────────────────────────────────

    public function test_show_returns_product_when_found(): void
    {
        $this->mockRepository
            ->shouldReceive('findById')
            ->once()
            ->with(1)
            ->andReturn(['id' => 1, 'name' => 'Samsung TV', 'price' => 1299.99]);

        $this->getJson('/api/products/1')
            ->assertStatus(200)
            ->assertJsonPath('data.id', 1)
            ->assertJsonPath('data.name', 'Samsung TV');
    }

    public function test_show_returns_404_when_not_found(): void
    {
        $this->mockRepository
            ->shouldReceive('findById')
            ->once()
            ->with(9999)
            ->andReturn([]);

        $this->getJson('/api/products/9999')->assertStatus(404);
    }

    // ── Suggest endpoint ──────────────────────────────────────────────────────

    public function test_suggest_returns_suggestions(): void
    {
        $this->mockRepository
            ->shouldReceive('suggest')
            ->once()
            ->with('sam')
            ->andReturn(['Samsung Galaxy S24', 'Samsung TV 4K', 'Samsung Laptop']);

        $response = $this->getJson('/api/products/suggest?q=sam');

        $response->assertStatus(200)
            ->assertJsonStructure(['suggestions'])
            ->assertJsonCount(3, 'suggestions');
    }

    public function test_suggest_requires_minimum_2_characters(): void
    {
        $this->mockRepository->shouldNotReceive('suggest');

        $this->getJson('/api/products/suggest?q=s')->assertStatus(422);
    }

    public function test_suggest_requires_q_parameter(): void
    {
        $this->mockRepository->shouldNotReceive('suggest');

        $this->getJson('/api/products/suggest')->assertStatus(422);
    }
}
