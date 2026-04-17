<?php

namespace Tests\Feature;

use App\Services\ElasticsearchService;
use App\Services\Repositories\ProductSearchRepository;
use Mockery;
use Tests\TestCase;

class AggregationTest extends TestCase
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

    public function test_aggregations_are_formatted_correctly(): void
    {
        $rawAggs = [
            'categories' => [
                'buckets' => [
                    ['key' => 'electronics', 'doc_count' => 45],
                    ['key' => 'clothing',    'doc_count' => 30],
                ],
            ],
            'brands' => [
                'buckets' => [
                    ['key' => 'Samsung', 'doc_count' => 20],
                    ['key' => 'Apple',   'doc_count' => 15],
                ],
            ],
            'tags_cloud' => [
                'buckets' => [
                    ['key' => 'wireless', 'doc_count' => 25],
                ],
            ],
            'price_ranges' => [
                'buckets' => [
                    ['key' => 'under_50',   'doc_count' => 10, 'to'   => 50.0],
                    ['key' => '50_to_200',  'doc_count' => 20, 'from' => 50.0, 'to' => 200.0],
                    ['key' => 'over_1000',  'doc_count' => 5,  'from' => 1000.0],
                ],
            ],
            'avg_price'     => ['value' => 299.99],
            'max_price'     => ['value' => 2499.99],
            'min_price'     => ['value' => 9.99],
            'total_stock'   => ['value' => 1500.0],
            'unique_brands' => ['value' => 8],
        ];

        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->andReturn($this->makeSearchResponse([], 0, $rawAggs));

        $result = $this->repository->search('');
        $aggs   = $result['aggregations'];

        // Categories
        $this->assertCount(2, $aggs['categories']);
        $this->assertEquals('electronics', $aggs['categories'][0]['name']);
        $this->assertEquals(45, $aggs['categories'][0]['count']);

        // Brands
        $this->assertEquals('Samsung', $aggs['brands'][0]['name']);
        $this->assertEquals(20, $aggs['brands'][0]['count']);

        // Tags
        $this->assertEquals('wireless', $aggs['tags'][0]['tag']);

        // Price ranges
        $this->assertEquals('under_50', $aggs['price_ranges'][0]['label']);
        $this->assertNull($aggs['price_ranges'][0]['from']);
        $this->assertEquals(50.0, $aggs['price_ranges'][0]['to']);

        // Price stats
        $this->assertEquals(299.99, $aggs['price_stats']['avg']); // round(299.99, 2)
        $this->assertEquals(2499.99, $aggs['price_stats']['max']);
        $this->assertEquals(9.99, $aggs['price_stats']['min']);

        // Totals
        $this->assertEquals(1500, $aggs['total_stock']);
        $this->assertEquals(8, $aggs['unique_brands']);
    }

    public function test_aggregations_handle_empty_buckets(): void
    {
        $rawAggs = [
            'categories'    => ['buckets' => []],
            'brands'        => ['buckets' => []],
            'tags_cloud'    => ['buckets' => []],
            'price_ranges'  => ['buckets' => []],
            'avg_price'     => ['value' => null],
            'max_price'     => ['value' => null],
            'min_price'     => ['value' => null],
            'total_stock'   => ['value' => 0],
            'unique_brands' => ['value' => 0],
        ];

        $this->mockEs
            ->shouldReceive('search')
            ->once()
            ->andReturn($this->makeSearchResponse([], 0, $rawAggs));

        $result = $this->repository->search('');
        $aggs   = $result['aggregations'];

        $this->assertEmpty($aggs['categories']);
        $this->assertEmpty($aggs['brands']);
        $this->assertEmpty($aggs['price_ranges']);
        $this->assertEquals(0, $aggs['price_stats']['avg']);
        $this->assertEquals(0, $aggs['total_stock']);
    }
}
