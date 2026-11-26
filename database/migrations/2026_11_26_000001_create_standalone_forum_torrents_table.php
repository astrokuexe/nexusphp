<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('standalone_forum_torrents')) {
            Schema::create('standalone_forum_torrents', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedSmallInteger('forum_id');
                $table->unsignedMediumInteger('torrent_id')->unique();
                $table->dateTime('created_at')->nullable();
                $table->index(['forum_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('standalone_forum_torrents');
    }
};
