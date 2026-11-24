<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('agent_allowed_family')) {
            return;
        }

        DB::table('agent_allowed_family')
            ->where('family', 'BiglyBT 3.x')
            ->update(['agent_pattern' => '/^BiglyBT\\/3\\.([0-9])\\.([0-9])\\.([0-9])/']);

        \Nexus\Database\NexusDB::cache_del('allowed_client_list');
        \Nexus\Database\NexusDB::cache_del(nexus_env('CACHE_KEY_AGENT_ALLOW', 'all_agent_allows') . ':php');
    }

    public function down(): void
    {
    }
};
