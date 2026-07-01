<?php

namespace App\Contracts;

interface SearchRepositoryInterface
{
    /**
     * Full-text + filtered search with pagination, sorting, highlighting, and aggregations.
     *
     * @param  string  $query    Free-text search string (empty for match-all)
     * @param  array   $filters  Narrow results: category, brand, tags, price_min/max, in_stock
     * @param  array   $options  Presentation + tenant: sort, page, per_page, cursor,
     *                           geo_lat/lon/distance, from_date/to_date, tenant
     * @return array             Shaped response with data, total, pagination, aggregations, took
     */
    public function search(string $query, array $filters = [], array $options = []): array;

    /**
     * Retrieve a single document by its Elasticsearch document ID, scoped to a
     * tenant. Returns empty array when not found or owned by another tenant.
     */
    public function findById(int $id, string $tenant = 'default'): array;

    /**
     * Prefix-based autocomplete suggestions for a given search string, isolated
     * to the tenant via the completion field's tenant context.
     * Returns an array of matching name strings.
     */
    public function suggest(string $prefix, string $tenant = 'default'): array;
}
