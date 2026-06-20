<?php

namespace Tests\Feature;

use App\Services\ElasticsearchService;
use Mockery;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function mockHealth(array $health): void
    {
        $es = Mockery::mock(ElasticsearchService::class);
        $es->shouldReceive('clusterHealth')->andReturn($health);
        $this->app->instance(ElasticsearchService::class, $es);
    }

    public function test_returns_ok_when_cluster_is_green(): void
    {
        $this->mockHealth(['status' => 'green', 'number_of_nodes' => 1]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJson([
                'status'        => 'ok',
                'elasticsearch' => ['reachable' => true, 'status' => 'green', 'nodes' => 1],
            ]);
    }

    public function test_yellow_is_still_ok(): void
    {
        // Single-node indexes sit at yellow (no replica) — that is healthy
        $this->mockHealth(['status' => 'yellow', 'number_of_nodes' => 1]);

        $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'ok');
    }

    public function test_red_cluster_is_degraded_503(): void
    {
        $this->mockHealth(['status' => 'red', 'number_of_nodes' => 1]);

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('elasticsearch.status', 'red');
    }

    public function test_unreachable_elasticsearch_is_degraded_503(): void
    {
        $this->mockHealth([]); // empty array = clusterHealth swallowed an exception

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('elasticsearch.reachable', false)
            ->assertJsonPath('elasticsearch.status', null);
    }
}
