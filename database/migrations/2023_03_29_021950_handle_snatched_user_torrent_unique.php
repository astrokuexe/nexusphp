<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
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
        $tableName = 'snatched';
        $columnNames = ['torrentid', 'userid'];
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
            if (!NexusDB::hasIndex($tableName, 'snatched_torrentid_userid_unique')) {
                $table->unique(['torrentid', 'userid']);
            }
            if (!NexusDB::hasIndex($tableName, 'snatched_userid_index')) {
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
        //
    }
};
