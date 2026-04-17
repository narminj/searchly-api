<?php

namespace App\Contracts;

interface SearchRepositoryInterface
{
    /**
     * Full-text + filtered search with pagination, sorting, highlighting, and aggregations.
     *
     * @param  string  $query    Free-text search string (empty for match-all)
     * @param  array   $filters  Narrow results: category, brand, tags, price_min/max, in_stock
     * @param  array   $options  Presentation: sort, page, per_page, geo_lat/lon/distance, from_date/to_date
     * @return array             Shaped response with data, total, pagination, aggregations, took
     */
    public function search(string $query, array $filters = [], array $options = []): array;

    /**
     * Retrieve a single document by its Elasticsearch document ID.
     * Returns empty array when not found.
     */
    public function findById(int $id): array;

    /**
     * Execute a custom aggregation query and return the raw aggregation buckets.
     */
    public function aggregate(array $params): array;

    /**
     * Prefix-based autocomplete suggestions for a given search string.
     * Returns an array of matching name strings.
     */
    public function suggest(string $prefix): array;
}
