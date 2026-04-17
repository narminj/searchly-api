<?php

namespace App\Services;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Elastic\Transport\Exception\NoNodeAvailableException;
use Illuminate\Support\Facades\Log;

/**
 * Generic, index-agnostic Elasticsearch adapter.
 * Knows nothing about specific domains (products, orders, etc.).
 * Domain-specific query logic lives in repository classes.
 */
class ElasticsearchService
{
    public function __construct(private readonly Client $client) {}

    // -------------------------------------------------------------------------
    // Index Management
    // -------------------------------------------------------------------------

    public function createIndex(string $index, array $settings = [], array $mappings = []): bool
    {
        try {
            $params = ['index' => $index, 'body' => []];

            if ($settings) {
                $params['body']['settings'] = $settings;
            }
            if ($mappings) {
                $params['body']['mappings'] = $mappings;
            }

            $response = $this->client->indices()->create($params);

            return $response->asBool();
        } catch (ClientResponseException | ServerResponseException $e) {
            Log::error('ES createIndex failed', ['index' => $index, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function deleteIndex(string $index): bool
    {
        try {
            if (! $this->existsIndex($index)) {
                return false;
            }

            return $this->client->indices()->delete(['index' => $index])->asBool();
        } catch (ClientResponseException | ServerResponseException $e) {
            Log::error('ES deleteIndex failed', ['index' => $index, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function existsIndex(string $index): bool
    {
        try {
            return $this->client->indices()->exists(['index' => $index])->asBool();
        } catch (ClientResponseException | ServerResponseException | NoNodeAvailableException $e) {
            Log::warning('ES existsIndex failed', ['index' => $index, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Update index mappings without reindexing.
     * Useful for adding new fields. Cannot change existing field types.
     */
    public function putMapping(string $index, array $mappings): bool
    {
        try {
            return $this->client->indices()->putMapping([
                'index' => $index,
                'body'  => $mappings,
            ])->asBool();
        } catch (ClientResponseException | ServerResponseException $e) {
            Log::error('ES putMapping failed', ['index' => $index, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Force a refresh so newly indexed documents are immediately searchable.
     * Use in tests and after bulk operations where you need immediate visibility.
     */
    public function refreshIndex(string $index): void
    {
        try {
            $this->client->indices()->refresh(['index' => $index]);
        } catch (ClientResponseException | ServerResponseException $e) {
            Log::warning('ES refreshIndex failed', ['index' => $index, 'error' => $e->getMessage()]);
        }
    }

    public function getIndexStats(string $index): array
    {
        try {
            return $this->client->indices()->stats(['index' => $index])->asArray();
        } catch (ClientResponseException | ServerResponseException $e) {
            Log::error('ES getIndexStats failed', ['index' => $index, 'error' => $e->getMessage()]);

            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Document CRUD
    // -------------------------------------------------------------------------

    public function indexDocument(string $index, int|string $id, array $body): array
    {
        try {
            return $this->client->index([
                'index' => $index,
                'id'    => $id,
                'body'  => $body,
            ])->asArray();
        } catch (ClientResponseException | ServerResponseException $e) {
            Log::error('ES indexDocument failed', ['index' => $index, 'id' => $id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Partial update — only the provided fields are updated in the existing document.
     */
    public function updateDocument(string $index, int|string $id, array $fields): array
    {
        try {
            return $this->client->update([
                'index' => $index,
                'id'    => $id,
                'body'  => ['doc' => $fields],
            ])->asArray();
        } catch (ClientResponseException | ServerResponseException $e) {
            Log::error('ES updateDocument failed', ['index' => $index, 'id' => $id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function deleteDocument(string $index, int|string $id): array
    {
        try {
            return $this->client->delete([
                'index' => $index,
                'id'    => $id,
            ])->asArray();
        } catch (ClientResponseException | ServerResponseException $e) {
            Log::error('ES deleteDocument failed', ['index' => $index, 'id' => $id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function getDocument(string $index, int|string $id): array
    {
        try {
            return $this->client->get([
                'index' => $index,
                'id'    => $id,
            ])->asArray();
        } catch (ClientResponseException $e) {
            if ($e->getCode() === 404) {
                return [];
            }
            Log::error('ES getDocument failed', ['index' => $index, 'id' => $id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Bulk Operations
    // -------------------------------------------------------------------------

    /**
     * Bulk index an array of documents.
     * Each document must have an 'id' field.
     * 10-50x faster than individual index calls for large datasets.
     */
    public function bulkIndex(string $index, array $documents): array
    {
        if (empty($documents)) {
            return [];
        }

        $body = [];
        foreach ($documents as $document) {
            $body[] = ['index' => ['_index' => $index, '_id' => $document['id']]];
            $body[] = $document;
        }

        try {
            return $this->client->bulk(['body' => $body])->asArray();
        } catch (ClientResponseException | ServerResponseException $e) {
            Log::error('ES bulkIndex failed', ['index' => $index, 'count' => count($documents), 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Bulk delete documents by their IDs.
     */
    public function bulkDelete(string $index, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $body = [];
        foreach ($ids as $id) {
            $body[] = ['delete' => ['_index' => $index, '_id' => $id]];
        }

        try {
            return $this->client->bulk(['body' => $body])->asArray();
        } catch (ClientResponseException | ServerResponseException $e) {
            Log::error('ES bulkDelete failed', ['index' => $index, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Search
    // -------------------------------------------------------------------------

    /**
     * Execute a raw Elasticsearch query DSL body and return the full response.
     * Query building is the responsibility of repository classes.
     */
    public function search(string $index, array $body): array
    {
        try {
            return $this->client->search([
                'index' => $index,
                'body'  => $body,
            ])->asArray();
        } catch (ClientResponseException | ServerResponseException $e) {
            Log::error('ES search failed', ['index' => $index, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Count documents matching a query.
     */
    public function count(string $index, array $query = []): int
    {
        try {
            $params = ['index' => $index];
            if ($query) {
                $params['body'] = ['query' => $query];
            }

            return $this->client->count($params)->asArray()['count'] ?? 0;
        } catch (ClientResponseException | ServerResponseException | NoNodeAvailableException $e) {
            Log::error('ES count failed', ['index' => $index, 'error' => $e->getMessage()]);

            return 0;
        }
    }

    public function getClient(): Client
    {
        return $this->client;
    }
}
