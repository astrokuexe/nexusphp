<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invite_registration_requests')) {
            return;
        }

        Schema::create('invite_registration_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('invite_id')->unique();
            $table->string('username', 40);
            $table->string('email', 80);
            $table->string('passhash', 64);
            $table->string('secret', 64);
            $table->unsignedSmallInteger('lang')->default(0);
            $table->unsignedTinyInteger('stylesheet')->default(1);
            $table->string('ip', 64)->default('');
            $table->string('status', 16)->default('pending')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invite_registration_requests');
    }
};
