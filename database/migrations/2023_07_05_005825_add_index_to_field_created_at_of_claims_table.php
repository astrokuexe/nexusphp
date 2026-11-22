<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('claims', function (Blueprint $table) {
            if (!\Nexus\Database\NexusDB::hasIndex('claims', 'claims_created_at_index')
                && !\Nexus\Database\NexusDB::hasIndex('claims', 'idx_created_at')) {
                $table->index('created_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('claims', function (Blueprint $table) {
            if (\Nexus\Database\NexusDB::hasIndex('claims', 'idx_created_at')) {
                $table->dropIndex('idx_created_at');
            } elseif (\Nexus\Database\NexusDB::hasIndex('claims', 'claims_created_at_index')) {
                $table->dropIndex('claims_created_at_index');
            }
        });
    }
};
