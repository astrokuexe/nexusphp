<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('standalone_forum_groups')) {
            Schema::create('standalone_forum_groups', function (Blueprint $table) {
                $table->smallIncrements('id');
                $table->string('name', 60);
                $table->string('description', 255)->default('');
                $table->unsignedSmallInteger('sort')->default(0);
            });
        }

        if (!Schema::hasTable('standalone_forums')) {
            Schema::create('standalone_forums', function (Blueprint $table) {
                $table->smallIncrements('id');
                $table->unsignedSmallInteger('group_id');
                $table->string('name', 60);
                $table->string('description', 255)->default('');
                $table->unsignedSmallInteger('sort')->default(0);
                $table->unsignedTinyInteger('minclassread')->default(0);
                $table->unsignedTinyInteger('minclasswrite')->default(0);
                $table->unsignedTinyInteger('minclasscreate')->default(0);
                $table->unsignedInteger('topiccount')->default(0);
                $table->unsignedInteger('postcount')->default(0);
                $table->index(['group_id', 'sort']);
            });
        }

        if (!Schema::hasTable('standalone_forum_topics')) {
            Schema::create('standalone_forum_topics', function (Blueprint $table) {
                $table->mediumIncrements('id');
                $table->unsignedSmallInteger('forum_id');
                $table->unsignedMediumInteger('user_id')->index();
                $table->string('subject', 128);
                $table->unsignedInteger('first_post_id')->default(0);
                $table->unsignedInteger('last_post_id')->default(0);
                $table->unsignedInteger('views')->default(0);
                $table->boolean('locked')->default(false);
                $table->dateTime('created_at')->nullable();
                $table->index(['forum_id', 'last_post_id']);
            });
        }

        if (!Schema::hasTable('standalone_forum_posts')) {
            Schema::create('standalone_forum_posts', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedMediumInteger('topic_id');
                $table->unsignedMediumInteger('user_id')->index();
                $table->text('body');
                $table->dateTime('created_at')->nullable();
                $table->index(['topic_id', 'id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('standalone_forum_posts');
        Schema::dropIfExists('standalone_forum_topics');
        Schema::dropIfExists('standalone_forums');
        Schema::dropIfExists('standalone_forum_groups');
    }
};
