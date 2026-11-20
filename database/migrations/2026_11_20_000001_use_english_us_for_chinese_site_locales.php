<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nexus\Database\NexusDB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            DB::table('users')->whereIn('lang', [25, 28])->update(['lang' => 6]);
        }

        if (Schema::hasTable('language')) {
            DB::table('language')->where('id', 6)->update([
                'lang_name' => 'English (US)',
                'flagpic' => 'usa.gif',
            ]);
            DB::table('language')->where('id', 25)->update([
                'lang_name' => 'Chinese (Simplified)',
                'rule_lang' => 0,
                'site_lang' => 0,
                'trans_state' => 'unavailable',
            ]);
            DB::table('language')->where('id', 28)->update([
                'lang_name' => 'Chinese (Traditional)',
                'rule_lang' => 0,
                'site_lang' => 0,
                'trans_state' => 'unavailable',
            ]);
        }

        if (Schema::hasTable('settings')) {
            DB::table('settings')
                ->where('name', 'main.site_language_enabled')
                ->update(['value' => json_encode(['en'])]);
            DB::table('settings')
                ->where('name', 'main.defaultlang')
                ->whereIn('value', ['chs', 'cht'])
                ->update(['value' => 'en']);
        }

        NexusDB::cache_del('nexus_settings_in_laravel');
        NexusDB::cache_del('nexus_settings_in_nexus');
        NexusDB::cache_del('site_lang_lang_list');
    }

    public function down(): void
    {
        // Locale preferences are intentionally migrated to English (US).
    }
};
