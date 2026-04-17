<?php

namespace App\Providers;

use App\Contracts\SearchRepositoryInterface;
use App\Services\ElasticsearchService;
use App\Services\Repositories\ProductSearchRepository;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Illuminate\Support\ServiceProvider;

class ElasticsearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton Elasticsearch Client — one connection per process lifetime
        $this->app->singleton(Client::class, function () {
            $cfg     = config('elasticsearch.connections.' . config('elasticsearch.default'));
            $builder = ClientBuilder::create()
                ->setHosts($cfg['hosts'])
                ->setRetries($cfg['retries']);

            // Authentication priority: api_key > cloud_id > basic auth
            if (! empty($cfg['api_key'])) {
                $builder->setApiKey($cfg['api_key']);
            } elseif (! empty($cfg['cloud_id'])) {
                $builder->setElasticCloudId($cfg['cloud_id']);
                if (! empty($cfg['username'])) {
                    $builder->setBasicAuthentication($cfg['username'], $cfg['password']);
                }
            } elseif (! empty($cfg['username'])) {
                $builder->setBasicAuthentication($cfg['username'], $cfg['password']);
            }

            if (! $cfg['ssl_verification']) {
                $builder->setSSLVerification(false);
            }

            return $builder->build();
        });

        // ElasticsearchService depends on Client (auto-resolved by the container)
        $this->app->singleton(ElasticsearchService::class);

        // Bind the search repository contract to the product implementation
        $this->app->bind(SearchRepositoryInterface::class, ProductSearchRepository::class);
    }

    public function boot(): void
    {
        //
    }
}
