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

    public function createIndex(string $index, array $settings = [], array $mappings = [], array $aliases = []): bool
    {
        try {
            $params = ['index' => $index, 'body' => []];

            if ($settings) {
                $params['body']['settings'] = $settings;
            }
            if ($mappings) {
                $params['body']['mappings'] = $mappings;
            }
            if ($aliases) {
                $params['body']['aliases'] = $aliases;
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

    /**
     * Cluster health (status, node count, active shards). Returns an empty array
     * when Elasticsearch is unreachable — catches every Throwable on purpose so
     * the health endpoint can report "down" instead of erroring out.
     */
    public function clusterHealth(): array
    {
        try {
            return $this->client->cluster()->health()->asArray();
        } catch (\Throwable $e) {
            Log::error('ES clusterHealth failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Aliases & Versioned Indices
    // -------------------------------------------------------------------------

    /**
     * Physical indices behind an alias. Empty array when the alias doesn't exist.
     */
    public function getAliasIndices(string $alias): array
    {
        try {
            if (! $this->client->indices()->existsAlias(['name' => $alias])->asBool()) {
                return [];
            }

            return array_keys($this->client->indices()->getAlias(['name' => $alias])->asArray());
        } catch (ClientResponseException | ServerResponseException | NoNodeAvailableException $e) {
            Log::warning('ES getAliasIndices failed', ['alias' => $alias, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Atomically apply a set of alias actions (add / remove / remove_index).
     * All actions succeed or fail together — this is what makes zero-downtime
     * index swaps possible.
     */
    public function updateAliases(array $actions): bool
    {
        try {
            return $this->client->indices()->updateAliases(['body' => ['actions' => $actions]])->asBool();
        } catch (ClientResponseException | ServerResponseException $e) {
            Log::error('ES updateAliases failed', ['actions' => $actions, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Index names matching a pattern (e.g. "products_v*").
     */
    public function listIndices(string $pattern): array
    {
        try {
            return array_keys($this->client->indices()->get(['index' => $pattern])->asArray());
        } catch (ClientResponseException $e) {
            if ($e->getCode() === 404) {
                return [];
            }
            Log::error('ES listIndices failed', ['pattern' => $pattern, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Update dynamic index settings (refresh_interval, number_of_replicas, …).
     */
    public function putSettings(string $index, array $settings): bool
    {
        try {
            return $this->client->indices()->putSettings([
                'index' => $index,
                'body'  => ['index' => $settings],
            ])->asBool();
        } catch (ClientResponseException | ServerResponseException $e) {
            Log::error('ES putSettings failed', ['index' => $index, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Synonyms (ES 8.10+ Synonyms API)
    // -------------------------------------------------------------------------

    /**
     * Create or replace a synonyms set. Search analyzers referencing the set
     * via an updateable synonym_graph filter are reloaded automatically —
     * dictionary changes need no reindex.
     */
    public function putSynonymsSet(string $id, array $rules): array
    {
        try {
            return $this->client->synonyms()->putSynonym([
                'id'   => $id,
                'body' => ['synonyms_set' => $rules],
            ])->asArray();
        } catch (ClientResponseException | ServerResponseException $e) {
            Log::error('ES putSynonymsSet failed', ['id' => $id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function getSynonymsSet(string $id): array
    {
        try {
            return $this->client->synonyms()->getSynonym(['id' => $id])->asArray();
        } catch (ClientResponseException $e) {
            if ($e->getCode() === 404) {
                return [];
            }
            Log::error('ES getSynonymsSet failed', ['id' => $id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Document CRUD
    // -------------------------------------------------------------------------

    /**
     * Index a document. Pass null as $id for ES auto-generated IDs
     * (append-only data like analytics logs).
     */
    public function indexDocument(string $index, int|string|null $id, array $body): array
    {
        try {
            $params = ['index' => $index, 'body' => $body];
            if ($id !== null) {
                $params['id'] = $id;
            }

            return $this->client->index($params)->asArray();
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
        } catch (ClientResponseException $e) {
            // Missing document = already deleted; treat as success so delete
            // jobs are idempotent and don't burn retries on a 404
            if ($e->getCode() === 404) {
                return [];
            }
            Log::error('ES deleteDocument failed', ['index' => $index, 'id' => $id, 'error' => $e->getMessage()]);
            throw $e;
        } catch (ServerResponseException $e) {
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
