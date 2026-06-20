<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Click-through counter; lives in the DB (not only in ES) so a
            // full reindex from the source of truth never loses it
            $table->unsignedInteger('popularity')->default(0)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('popularity');
        });
    }
};
