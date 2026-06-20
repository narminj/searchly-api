<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multi-tenancy: every product belongs to a tenant. Existing rows default to
     * 'default' so single-tenant behaviour is preserved. The column is indexed
     * because every tenant-scoped query filters on it; Elasticsearch isolation
     * is enforced separately via the tenant_id term filter and filtered aliases.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('tenant_id')->default('default')->index()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
