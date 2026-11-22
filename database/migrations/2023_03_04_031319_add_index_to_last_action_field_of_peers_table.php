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
        Schema::table('peers', function (Blueprint $table) {
            if (!\Nexus\Database\NexusDB::hasIndex('peers', 'peers_last_action_index')) {
                $table->index('last_action');
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
        Schema::table('peers', function (Blueprint $table) {
            if (\Nexus\Database\NexusDB::hasIndex('peers', 'peers_last_action_index')) {
                $table->dropIndex('peers_last_action_index');
            }
        });
    }
};
