<?php

namespace Tests\Feature;

use App\Jobs\DeleteProductFromIndex;
use App\Jobs\IndexProduct;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProductIndexingTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_product_dispatches_index_job(): void
    {
        Queue::fake();

        Product::factory()->create();

        Queue::assertPushed(IndexProduct::class, 1);
    }

    public function test_updating_product_dispatches_index_job(): void
    {
        Queue::fake();

        $product = Product::factory()->create();

        Queue::assertPushed(IndexProduct::class, 1);
        Queue::fake(); // Reset

        $product->update(['price' => 999.99]);

        Queue::assertPushed(IndexProduct::class, 1);
    }

    public function test_deleting_product_dispatches_delete_job(): void
    {
        Queue::fake();

        $product   = Product::factory()->create();
        $productId = $product->id;

        Queue::fake(); // Reset after create
        $product->delete();

        Queue::assertPushed(DeleteProductFromIndex::class, function ($job) use ($productId) {
            return $job->productId === $productId;
        });
    }

    public function test_index_job_contains_correct_product(): void
    {
        Queue::fake();

        $product = Product::factory()->create(['name' => 'Test Phone X99']);

        Queue::assertPushed(IndexProduct::class, function ($job) use ($product) {
            return $job->product->id === $product->id
                && $job->product->name === 'Test Phone X99';
        });
    }

    public function test_to_search_array_has_required_fields(): void
    {
        Queue::fake(); // prevent the IndexProduct job from calling ES

        $product = Product::factory()->create([
            'name'      => 'Galaxy Watch',
            'category'  => 'electronics',
            'brand'     => 'Samsung',
            'price'     => 299.99,
            'stock'     => 50,
            'tags'      => ['smartwatch', 'wearable'],
            'is_active' => true,
            'latitude'  => 40.7128,
            'longitude' => -74.0060,
        ]);

        $array = $product->toSearchArray();

        $this->assertEquals($product->id, $array['id']);
        $this->assertEquals('Galaxy Watch', $array['name']);
        $this->assertEquals('electronics', $array['category']);
        $this->assertEquals('Samsung', $array['brand']);
        $this->assertEquals(299.99, $array['price']);
        $this->assertEquals(50, $array['stock']);
        $this->assertEquals(['smartwatch', 'wearable'], $array['tags']);
        $this->assertTrue($array['is_active']);
        $this->assertEquals(['lat' => 40.7128, 'lon' => -74.0060], $array['location']);
        $this->assertNotNull($array['created_at']);
    }

    public function test_to_search_array_location_is_null_without_coordinates(): void
    {
        Queue::fake();

        $product = Product::factory()->create(['latitude' => null, 'longitude' => null]);

        $array = $product->toSearchArray();

        $this->assertNull($array['location']);
    }

    public function test_get_search_index_returns_configured_name(): void
    {
        Queue::fake();

        $product = Product::factory()->create();

        $this->assertEquals(
            config('elasticsearch.indices.products.name'),
            $product->getSearchIndex()
        );
    }
}
