<?php

namespace Tests\Feature;

use App\Contracts\SearchRepositoryInterface;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class MultiTenancyTest extends TestCase
{
    use RefreshDatabase;

    private SearchRepositoryInterface $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = Mockery::mock(SearchRepositoryInterface::class);
        $this->app->instance(SearchRepositoryInterface::class, $this->repo);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function searchResult(): array
    {
        return [
            'data' => [], 'total' => 0, 'per_page' => 15, 'current_page' => 1,
            'last_page' => 1, 'aggregations' => null, 'took_ms' => 1, 'max_score' => null,
        ];
    }

    public function test_x_tenant_header_is_passed_to_search(): void
    {
        $this->repo->shouldReceive('search')->once()
            ->withArgs(fn ($q, $f, $o) => ($o['tenant'] ?? null) === 'acme')
            ->andReturn($this->searchResult());

        $this->getJson('/api/products/search', ['X-Tenant-ID' => 'acme'])->assertOk();
    }

    public function test_missing_header_defaults_to_default_tenant(): void
    {
        $this->repo->shouldReceive('search')->once()
            ->withArgs(fn ($q, $f, $o) => ($o['tenant'] ?? null) === 'default')
            ->andReturn($this->searchResult());

        $this->getJson('/api/products/search')->assertOk();
    }

    public function test_invalid_header_falls_back_to_default_tenant(): void
    {
        $this->repo->shouldReceive('search')->once()
            ->withArgs(fn ($q, $f, $o) => ($o['tenant'] ?? null) === 'default')
            ->andReturn($this->searchResult());

        // Space + bang are not valid identifier characters
        $this->getJson('/api/products/search', ['X-Tenant-ID' => 'bad tenant!!'])->assertOk();
    }

    public function test_show_is_scoped_to_tenant(): void
    {
        $this->repo->shouldReceive('findById')->once()->with(5, 'acme')->andReturn([]);

        $this->getJson('/api/products/5', ['X-Tenant-ID' => 'acme'])->assertStatus(404);
    }

    public function test_suggest_is_scoped_to_tenant(): void
    {
        $this->repo->shouldReceive('suggest')->once()->with('sa', 'acme')->andReturn([]);

        $this->getJson('/api/products/suggest?q=sa', ['X-Tenant-ID' => 'acme'])->assertOk();
    }

    public function test_click_is_isolated_by_tenant(): void
    {
        Queue::fake();
        $product = Product::factory()->create(['is_active' => true, 'popularity' => 5, 'tenant_id' => 'default']);

        // A click carrying a different tenant must not touch it
        $this->postJson("/api/products/{$product->id}/click", [], ['X-Tenant-ID' => 'acme'])
            ->assertStatus(404);
        $this->assertSame(5, $product->fresh()->popularity);

        // A click from the owning tenant succeeds
        $this->postJson("/api/products/{$product->id}/click")->assertNoContent();
        $this->assertSame(6, $product->fresh()->popularity);
    }
}
