<?php

namespace App\Console\Commands;

use App\Services\ElasticsearchService;
use Illuminate\Console\Command;

/**
 * Creates (or removes) a per-tenant filtered alias over the products index —
 * the canonical Elasticsearch multi-tenancy pattern. Reading through
 * `products__<tenant>` transparently filters to that tenant's documents and
 * routes to its shard, so a tenant-scoped client never even references
 * tenant_id. Application search isolates via the tenant_id term filter; this
 * alias is the index-level counterpart (useful for per-tenant ES clients,
 * dashboards, or restricted API keys).
 */
class ElasticsearchTenantAlias extends Command
{
    protected $signature = 'elasticsearch:tenant-alias
                            {tenant : Tenant identifier ([A-Za-z0-9_-])}
                            {--remove : Remove the tenant alias instead of creating it}';

    protected $description = 'Create/remove a filtered + routed alias (products__<tenant>) for tenant isolation.';

    public function handle(ElasticsearchService $es): int
    {
        $tenant = (string) $this->argument('tenant');

        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $tenant)) {
            $this->error('Invalid tenant id — allowed: letters, digits, underscore, hyphen (max 64).');

            return self::FAILURE;
        }

        $productsAlias = config('elasticsearch.indices.products.name');
        $indices       = $es->getAliasIndices($productsAlias);

        if (empty($indices)) {
            $this->error("Alias '{$productsAlias}' has no physical index. Run elasticsearch:migrate {$productsAlias} first.");

            return self::FAILURE;
        }

        $tenantAlias = "{$productsAlias}__{$tenant}";

        if ($this->option('remove')) {
            $es->updateAliases(array_map(
                fn (string $index) => ['remove' => ['index' => $index, 'alias' => $tenantAlias]],
                $indices,
            ));
            $this->info("Removed tenant alias '{$tenantAlias}'.");

            return self::SUCCESS;
        }

        $es->updateAliases(array_map(
            fn (string $index) => ['add' => [
                'index'   => $index,
                'alias'   => $tenantAlias,
                'filter'  => ['term' => ['tenant_id' => $tenant]],
                'routing' => $tenant,
            ]],
            $indices,
        ));

        $this->info(sprintf(
            "Filtered alias '%s' → [%s] (filter tenant_id=%s, routing=%s).",
            $tenantAlias,
            implode(', ', $indices),
            $tenant,
            $tenant,
        ));

        return self::SUCCESS;
    }
}
