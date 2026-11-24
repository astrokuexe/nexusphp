<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UploadedRealMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('uploaded')->default(0);
        });
        DB::table('users')->insert(['uploaded' => 536870912000]);
    }

    public function testMigrationAddsSeparateZeroedCounterWithoutChangingExistingCredits(): void
    {
        $migration = require base_path('database/migrations/2026_11_23_000000_add_uploaded_real_to_users.php');
        $migration->up();
        $migration->up();

        $user = DB::table('users')->first();

        $this->assertSame(536870912000, (int) $user->uploaded);
        $this->assertSame(0, (int) $user->uploaded_real);
    }
}
