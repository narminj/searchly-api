<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\ElasticsearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ElasticsearchMigrateTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $es;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->es = Mockery::mock(ElasticsearchService::class);
        $this->app->instance(ElasticsearchService::class, $this->es);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_migrate_builds_v1_and_replaces_legacy_index_atomically(): void
    {
        Product::factory()->count(3)->create(['is_active' => true]);
        Product::factory()->inactive()->create();

        // Legacy state: physical index occupies the alias name, no versions yet
        $this->es->shouldReceive('getAliasIndices')->with('products_test')->andReturn([]);
        $this->es->shouldReceive('existsIndex')->with('products_test')->andReturn(true);
        $this->es->shouldReceive('listIndices')->with('products_test_v*')->andReturn([]);

        $this->es->shouldReceive('createIndex')
            ->once()
            ->withArgs(function (string $index, array $settings) {
                // bulk-load tuning must be applied to the new index
                return $index === 'products_test_v1'
                    && $settings['number_of_replicas'] === 0
                    && $settings['refresh_interval'] === '-1';
            })
            ->andReturn(true);

        // Only the 3 active products are loaded
        $this->es->shouldReceive('bulkIndex')
            ->once()
            ->withArgs(fn (string $index, array $docs) => $index === 'products_test_v1' && count($docs) === 3)
            ->andReturn(['items' => []]);

        $this->es->shouldReceive('putSettings')->once()->andReturn(true);
        $this->es->shouldReceive('refreshIndex')->once();
        $this->es->shouldReceive('count')->with('products_test_v1')->andReturn(3);

        $this->es->shouldReceive('updateAliases')
            ->once()
            ->withArgs(function (array $actions) {
                return $actions === [
                    ['remove_index' => ['index' => 'products_test']],
                    ['add' => ['index' => 'products_test_v1', 'alias' => 'products_test', 'is_write_index' => true]],
                ];
            })
            ->andReturn(true);

        $this->artisan('elasticsearch:migrate')->assertExitCode(0);
    }

    public function test_migrate_swaps_from_previous_version(): void
    {
        Product::factory()->count(2)->create(['is_active' => true]);

        $this->es->shouldReceive('getAliasIndices')->with('products_test')->andReturn(['products_test_v1']);
        $this->es->shouldReceive('listIndices')->with('products_test_v*')->andReturn(['products_test_v1']);

        $this->es->shouldReceive('createIndex')
            ->once()
            ->withArgs(fn (string $index) => $index === 'products_test_v2')
            ->andReturn(true);
        $this->es->shouldReceive('bulkIndex')->once()->andReturn(['items' => []]);
        $this->es->shouldReceive('putSettings')->once()->andReturn(true);
        $this->es->shouldReceive('refreshIndex')->once();
        $this->es->shouldReceive('count')->with('products_test_v2')->andReturn(2);

        $this->es->shouldReceive('updateAliases')
            ->once()
            ->withArgs(function (array $actions) {
                return $actions === [
                    ['remove' => ['index' => 'products_test_v1', 'alias' => 'products_test']],
                    ['add' => ['index' => 'products_test_v2', 'alias' => 'products_test', 'is_write_index' => true]],
                ];
            })
            ->andReturn(true);

        $this->artisan('elasticsearch:migrate')->assertExitCode(0);
    }

    public function test_migrate_aborts_swap_on_count_mismatch(): void
    {
        Product::factory()->count(3)->create(['is_active' => true]);

        $this->es->shouldReceive('getAliasIndices')->andReturn([]);
        $this->es->shouldReceive('existsIndex')->andReturn(false);
        $this->es->shouldReceive('listIndices')->andReturn([]);
        $this->es->shouldReceive('createIndex')->once()->andReturn(true);
        $this->es->shouldReceive('bulkIndex')->once()->andReturn(['items' => []]);
        $this->es->shouldReceive('putSettings')->once()->andReturn(true);
        $this->es->shouldReceive('refreshIndex')->once();
        $this->es->shouldReceive('count')->andReturn(2); // DB has 3

        $this->es->shouldNotReceive('updateAliases');

        $this->artisan('elasticsearch:migrate')->assertExitCode(1);
    }
}
