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
        Schema::table('invites', function (Blueprint $table) {
            if (!Schema::hasColumn('invites', 'expired_at')) {
                $table->dateTime('expired_at')->nullable(true)->index();
            }
            if (!Schema::hasColumn('invites', 'created_at')) {
                $table->dateTime('created_at')->useCurrent();
            }
            if (!\Nexus\Database\NexusDB::hasIndex('invites', 'idx_inviter')
                && !\Nexus\Database\NexusDB::hasIndex('invites', 'invites_inviter_index')) {
                $table->index(['inviter']);
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
        Schema::table('invites', function (Blueprint $table) {
            $table->dropColumn('expired_at', 'created_at');
            if (\Nexus\Database\NexusDB::hasIndex('invites', 'idx_inviter')) {
                $table->dropIndex('idx_inviter');
            } elseif (\Nexus\Database\NexusDB::hasIndex('invites', 'invites_inviter_index')) {
                $table->dropIndex('invites_inviter_index');
            }
        });
    }
};
