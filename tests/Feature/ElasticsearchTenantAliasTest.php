<?php

namespace Tests\Feature;

use App\Services\ElasticsearchService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ElasticsearchTenantAliasTest extends TestCase
{
    private MockInterface $es;

    protected function setUp(): void
    {
        parent::setUp();
        $this->es = Mockery::mock(ElasticsearchService::class);
        $this->app->instance(ElasticsearchService::class, $this->es);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_creates_filtered_and_routed_alias(): void
    {
        $this->es->shouldReceive('getAliasIndices')->with('products_test')->andReturn(['products_test_v2']);

        $this->es->shouldReceive('updateAliases')
            ->once()
            ->withArgs(function (array $actions) {
                return $actions === [[
                    'add' => [
                        'index'   => 'products_test_v2',
                        'alias'   => 'products_test__acme',
                        'filter'  => ['term' => ['tenant_id' => 'acme']],
                        'routing' => 'acme',
                    ],
                ]];
            })
            ->andReturn(true);

        $this->artisan('elasticsearch:tenant-alias', ['tenant' => 'acme'])->assertExitCode(0);
    }

    public function test_remove_flag_drops_the_alias(): void
    {
        $this->es->shouldReceive('getAliasIndices')->andReturn(['products_test_v2']);

        $this->es->shouldReceive('updateAliases')
            ->once()
            ->withArgs(fn (array $actions) => $actions === [['remove' => ['index' => 'products_test_v2', 'alias' => 'products_test__acme']]])
            ->andReturn(true);

        $this->artisan('elasticsearch:tenant-alias', ['tenant' => 'acme', '--remove' => true])->assertExitCode(0);
    }

    public function test_rejects_invalid_tenant_id(): void
    {
        $this->es->shouldNotReceive('updateAliases');

        $this->artisan('elasticsearch:tenant-alias', ['tenant' => 'bad tenant!!'])
            ->expectsOutputToContain('Invalid tenant id')
            ->assertExitCode(1);
    }

    public function test_fails_when_products_alias_has_no_index(): void
    {
        $this->es->shouldReceive('getAliasIndices')->andReturn([]);
        $this->es->shouldNotReceive('updateAliases');

        $this->artisan('elasticsearch:tenant-alias', ['tenant' => 'acme'])
            ->expectsOutputToContain('no physical index')
            ->assertExitCode(1);
    }
}
