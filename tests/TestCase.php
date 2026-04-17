<?php

namespace Tests;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Build a mock Elasticsearch client backed by a PSR-18 mock HTTP client.
     * This avoids needing a live Elasticsearch server in tests.
     *
     * The mock client records queued responses in order.
     * Use queueMockResponse() in your test to set up what ES "returns".
     */
    protected function getMockEsClient(array &$mockClientRef = null): Client
    {
        // We rely on the symfony/http-client mock adapter included transitively
        // by laravel/framework. For a fully self-contained mock, override the
        // client in the container using $this->app->instance(Client::class, ...).
        return ClientBuilder::create()
            ->setHosts(['localhost:9200'])
            ->setSSLVerification(false)
            ->build();
    }

    /**
     * Build a minimal valid ES search response body.
     */
    protected function makeSearchResponse(array $hits = [], int $total = 0, array $aggs = []): array
    {
        return [
            'took'      => 1,
            'timed_out' => false,
            '_shards'   => ['total' => 1, 'successful' => 1, 'skipped' => 0, 'failed' => 0],
            'hits'      => [
                'total'     => ['value' => $total ?: count($hits), 'relation' => 'eq'],
                'max_score' => empty($hits) ? null : 1.5,
                'hits'      => $hits,
            ],
            'aggregations' => $aggs,
        ];
    }

    /**
     * Build a minimal ES hit document.
     */
    protected function makeHit(int $id, array $source = []): array
    {
        return [
            '_index'  => 'products_test',
            '_id'     => (string) $id,
            '_score'  => 1.5,
            '_source' => array_merge([
                'id'          => $id,
                'name'        => "Product {$id}",
                'description' => 'A test product description.',
                'category'    => 'electronics',
                'brand'       => 'TestBrand',
                'price'       => 99.99,
                'stock'       => 10,
                'tags'        => ['test', 'demo'],
                'is_active'   => true,
                'created_at'  => '2024-01-01 00:00:00',
                'updated_at'  => '2024-01-01 00:00:00',
            ], $source),
        ];
    }
}
