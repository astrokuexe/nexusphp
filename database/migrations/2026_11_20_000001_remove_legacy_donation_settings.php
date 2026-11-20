<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->whereIn('name', [
                'main.ACCOUNTANTID',
                'main.ALIPAYACCOUNT',
                'main.PAYPALACCOUNT',
            ])
            ->delete();
    }

    public function down(): void
    {
        // Removed legacy payment settings cannot be restored.
    }
};
