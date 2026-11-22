<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('categories') || Schema::hasColumn('categories', 'parent_id')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedSmallInteger('parent_id')->nullable()->after('mode');
            $table->index(['mode', 'parent_id'], 'mode_parent_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('categories') || !Schema::hasColumn('categories', 'parent_id')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table) {
            if (\Nexus\Database\NexusDB::hasIndex('categories', 'mode_parent_id')) {
                $table->dropIndex('mode_parent_id');
            }
            $table->dropColumn('parent_id');
        });
    }
};
