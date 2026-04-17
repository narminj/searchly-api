<?php

namespace Tests\Unit;

use App\Services\ElasticsearchService;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Tests\TestCase;

/**
 * Unit tests for ElasticsearchService that do not require a live ES server.
 * The Client class is marked final so it cannot be mocked with Mockery.
 * Tests here focus on logic that runs before or without calling the client.
 */
class ElasticsearchServiceTest extends TestCase
{
    private function makeService(): ElasticsearchService
    {
        $client = ClientBuilder::create()
            ->setHosts(['localhost:9200'])
            ->setSSLVerification(false)
            ->build();

        return new ElasticsearchService($client);
    }

    public function test_bulk_index_returns_empty_array_for_empty_documents(): void
    {
        $service = $this->makeService();

        // Empty documents short-circuits before hitting the network
        $result = $service->bulkIndex('products_test', []);

        $this->assertEmpty($result);
    }

    public function test_bulk_delete_returns_empty_array_for_empty_ids(): void
    {
        $service = $this->makeService();

        $result = $service->bulkDelete('products_test', []);

        $this->assertEmpty($result);
    }

    public function test_get_client_returns_client_instance(): void
    {
        $service = $this->makeService();

        $this->assertInstanceOf(Client::class, $service->getClient());
    }

    public function test_exists_index_returns_false_on_connection_failure(): void
    {
        // Uses unreachable host — existsIndex() catches the exception and returns false
        $client = ClientBuilder::create()
            ->setHosts(['unreachable-host:9999'])
            ->setRetries(0)
            ->setSSLVerification(false)
            ->build();

        $service = new ElasticsearchService($client);

        $result = $service->existsIndex('products_test');

        $this->assertFalse($result);
    }

    public function test_count_returns_zero_on_connection_failure(): void
    {
        $client = ClientBuilder::create()
            ->setHosts(['unreachable-host:9999'])
            ->setRetries(0)
            ->setSSLVerification(false)
            ->build();

        $service = new ElasticsearchService($client);

        $result = $service->count('products_test');

        $this->assertEquals(0, $result);
    }
}
