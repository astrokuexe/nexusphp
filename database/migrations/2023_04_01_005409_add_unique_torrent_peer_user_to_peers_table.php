<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nexus\Database\NexusDB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (NexusDB::isPgsql()) {
            return;
        }
        $tableName = 'peers';
        $columnNames = ['torrent', 'peer_id', 'userid'];
        // 1. 获取该表所有的索引信息
        $indexesToDelete = NexusDB::listColumnIndexNames($tableName, $columnNames);

        // 3. 执行删除操作
        Schema::table($tableName, function (Blueprint $table) use ($indexesToDelete, $tableName) {
            foreach (array_unique($indexesToDelete) as $indexName) {
                // 如果是主键，需要单独处理
                if ($indexName === 'primary') {
                    $table->dropPrimary();
                } elseif (NexusDB::hasIndex($tableName, $indexName)) {
                    $table->dropIndex($indexName);
                }
            }
            if (!NexusDB::hasIndex($tableName, 'peers_torrent_peer_id_userid_unique')) {
                $table->unique(['torrent', 'peer_id', 'userid']);
            }
            if (!NexusDB::hasIndex($tableName, 'peers_peer_id_index')) {
                $table->index('peer_id');
            }
            if (!NexusDB::hasIndex($tableName, 'peers_userid_index')) {
                $table->index('userid');
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
            //
        });
    }
};
