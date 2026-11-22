<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        if (\Nexus\Database\NexusDB::isPgsql()) {
            return;
        }
        $tableName = 'peers';
        $columnNames = ['torrent', 'peer_id'];
        // 1. 获取该表所有的索引信息
        $indexesToDelete = \Nexus\Database\NexusDB::listColumnIndexNames($tableName, $columnNames);

        // 3. 执行删除操作
        Schema::table($tableName, function (Blueprint $table) use ($indexesToDelete, $tableName) {
            foreach (array_unique($indexesToDelete) as $indexName) {
                // 如果是主键，需要单独处理
                if ($indexName === 'primary') {
                    $table->dropPrimary();
                } elseif (\Nexus\Database\NexusDB::hasIndex($tableName, $indexName)) {
                    $table->dropIndex($indexName);
                } else {
                    continue;
                }
            }
            if (!\Nexus\Database\NexusDB::hasIndex($tableName, 'peers_torrent_peer_id_index')) {
                $table->index(['torrent', 'peer_id']);
            }
            if (!\Nexus\Database\NexusDB::hasIndex($tableName, 'peers_peer_id_index')) {
                $table->index('peer_id');
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
        //
    }
};
