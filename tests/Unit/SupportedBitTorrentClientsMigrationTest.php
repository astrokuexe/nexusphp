<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupportedBitTorrentClientsMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
        ]);
        DB::purge('sqlite');

        Schema::create('agent_allowed_family', function (Blueprint $table) {
            $table->increments('id');
            $table->string('family');
            $table->string('start_name')->nullable();
            $table->string('peer_id_pattern')->nullable();
            $table->unsignedTinyInteger('peer_id_match_num')->nullable();
            $table->string('peer_id_matchtype')->nullable();
            $table->string('peer_id_start')->nullable();
            $table->string('agent_pattern')->nullable();
            $table->unsignedTinyInteger('agent_match_num')->nullable();
            $table->string('agent_matchtype')->nullable();
            $table->string('agent_start')->nullable();
            $table->string('exception')->nullable();
            $table->string('allowhttps')->nullable();
            $table->string('comment')->nullable();
            $table->unsignedInteger('hits')->nullable();
        });
        Schema::create('faq', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('link_id');
            $table->text('answer');
        });

        DB::table('agent_allowed_family')->insert([
            ['family' => 'uTorrent 3.x'],
            ['family' => 'BitComet 2.0'],
            ['family' => 'qBittorrent 4.x'],
            ['family' => 'Azureus 3.x'],
            ['family' => 'BitTorrent 6.x'],
            ['family' => 'Rufus 0.x'],
            ['family' => 'Enhanced CTorrent 3.x'],
            ['family' => 'BitRocket 0.x'],
            ['family' => 'SymTorrent 1.x'],
        ]);
        DB::table('agent_allowed_family')->insert([
            'family' => 'RTorrent 0.x(with libtorrent 0.x)',
            'start_name' => 'rTorrent 0.8.0 (with libtorrent 0.12.0)',
            'peer_id_pattern' => '/^-lt([0-9A-Z])([0-9A-Z])([0-9A-Z])([0-9A-Z])-/',
            'peer_id_match_num' => 4,
            'peer_id_matchtype' => 'hex',
            'peer_id_start' => '-lt0C00-',
            'agent_pattern' => '/^rtorrent\\/0\\.([0-9]{1,2})\\.([0-9])\\/0\\.([1-9][0-9]*)\\.(0|[1-9][0-9]*)/',
            'agent_match_num' => 4,
            'agent_matchtype' => 'dec',
            'agent_start' => 'rtorrent/0.8.0/0.12.0',
            'exception' => 'no',
            'allowhttps' => 'yes',
            'comment' => '',
            'hits' => 0,
        ]);
        DB::table('faq')->insert([
            'id' => 29,
            'link_id' => 29,
            'answer' => '<li><a class="faqlink" href="x">Azureus</a>: old</li>'
                . '<li><a class="faqlink" href="x">BitTorrent</a>: old</li>'
                . '<li><a class="faqlink" href="x">Rufus</a>: old</li>'
                . '<li><a class="faqlink" href="x">Enhanced CTorrent</a>: old</li>'
                . '<li><a class="faqlink" href="x">BitRocket</a>: old</li>'
                . '<b>Symbian:</b><ul><li><a class="faqlink" href="x">SymTorrent</a>: old</li></ul>'
                . 'some clients, like Azureus 2.0.7.0 or higher, use a single port'
                . 'Please do not use any beta or testing version of bittorrent clients, e.g. uTorrent 1.8.0B. To get the best downloading experience, we highly recommend latest stable version of <a class="faqlink" href="http://www.utorrent.com/download.php">uTorrent</a> and <a class="faqlink" href="http://azureus.sourceforge.net/download.php">Azureus</a>.',
        ]);
    }

    public function testMigrationAddsOnlyTheRequestedClientRules(): void
    {
        $migration = require base_path('database/migrations/2026_11_20_000000_update_supported_bittorrent_clients.php');
        $migration->up();

        $rules = DB::table('agent_allowed_family')->get()->keyBy('family');

        $this->assertCount(4, $rules);
        $this->assertArrayHasKey('uTorrent 2.2.1', $rules);
        $this->assertArrayHasKey('BitComet 2.21+', $rules);
        $this->assertArrayHasKey('qBittorrent 4.4.5+', $rules);
        $this->assertArrayHasKey('RTorrent 0.x(with libtorrent 0.x)', $rules);
        foreach (['Azureus', 'BitTorrent', 'Rufus', 'Enhanced CTorrent', 'BitRocket', 'SymTorrent'] as $family) {
            $this->assertFalse($rules->keys()->contains(fn (string $rule) => str_starts_with($rule, $family)));
        }

        $faqAnswer = DB::table('faq')->where('id', 29)->value('answer');
        foreach (['Azureus', 'BitTorrent', 'Rufus', 'Enhanced CTorrent', 'BitRocket', 'SymTorrent'] as $client) {
            $this->assertStringNotContainsString($client, $faqAnswer);
        }
        $this->assertStringContainsString('qBittorrent 4.4.5 and later', $faqAnswer);

        $this->assertTrue($this->clientMatches($rules['uTorrent 2.2.1'], '-UT2210-', 'uTorrent/2210'));
        $this->assertFalse($this->clientMatches($rules['uTorrent 2.2.1'], '-UT2210-', 'uTorrent/2211'));
        $this->assertFalse($this->clientMatches($rules['uTorrent 2.2.1'], '-UT3000-', 'uTorrent/3000'));

        $this->assertFalse($this->clientMatches($rules['BitComet 2.21+'], '-BC0210-', 'BitComet/2.20'));
        $this->assertTrue($this->clientMatches($rules['BitComet 2.21+'], '-BC0221-', 'BitComet/2.21.2'));
        $this->assertTrue($this->clientMatches($rules['BitComet 2.21+'], '-BC0300-', 'BitComet/3.0'));

        $this->assertFalse($this->clientMatches($rules['qBittorrent 4.4.5+'], '-qB4440-', 'qBittorrent/4.4.4'));
        $this->assertTrue($this->clientMatches($rules['qBittorrent 4.4.5+'], '-qB4450-', 'qBittorrent/4.4.5'));
        $this->assertTrue($this->clientMatches($rules['qBittorrent 4.4.5+'], '-qB44100-', 'qBittorrent/4.4.10'));
        $this->assertTrue($this->clientMatches($rules['qBittorrent 4.4.5+'], '-qB500-', 'qBittorrent/5.0.0'));

        $rtorrentMigration = require base_path('database/migrations/2026_11_24_020000_update_rtorrent_minimum_version.php');
        $rtorrentMigration->up();
        $rtorrentRules = DB::table('agent_allowed_family')->get()->keyBy('family');
        $this->assertArrayHasKey('rTorrent 0.9.8+ (libtorrent)', $rtorrentRules);
        $rtorrent = $rtorrentRules['rTorrent 0.9.8+ (libtorrent)'];
        $this->assertTrue($this->clientMatches($rtorrent, '-lt0D80-', 'rtorrent/0.9.8/0.13.8'));
        $this->assertTrue($this->clientMatches($rtorrent, '-lt0D80-', 'rtorrent/0.10.0/0.13.8'));
        $this->assertFalse($this->clientMatches($rtorrent, '-lt0D80-', 'rtorrent/0.9.7/0.13.7'));
        $this->assertFalse($this->clientMatches($rtorrent, '-lt0D70-', 'rtorrent/0.9.8/0.13.8'));
    }

    public function testFreshInstallSeederUsesTheSameClientRules(): void
    {
        $migration = require base_path('database/migrations/2026_11_20_000000_update_supported_bittorrent_clients.php');
        $migration->up();
        $rtorrentMigration = require base_path('database/migrations/2026_11_24_020000_update_rtorrent_minimum_version.php');
        $rtorrentMigration->up();
        $migrationRules = DB::table('agent_allowed_family')->get()->keyBy('family');

        (new \Database\Seeders\AgentAllowedFamilyTableSeeder())->run();
        $seededRules = DB::table('agent_allowed_family')->get()->keyBy('family');

        $biglybtPattern = $seededRules['BiglyBT 3.x']->agent_pattern;
        $this->assertSame(1, preg_match($biglybtPattern, 'BiglyBT/3.0.0.0'));

        foreach (['uTorrent 2.2.1', 'BitComet 2.21+', 'qBittorrent 4.4.5+', 'rTorrent 0.9.8+ (libtorrent)'] as $family) {
            $this->assertSame(
                [
                    $migrationRules[$family]->peer_id_pattern,
                    $migrationRules[$family]->peer_id_match_num,
                    $migrationRules[$family]->peer_id_start,
                    $migrationRules[$family]->agent_pattern,
                    $migrationRules[$family]->agent_match_num,
                    $migrationRules[$family]->agent_start,
                ],
                [
                    $seededRules[$family]->peer_id_pattern,
                    $seededRules[$family]->peer_id_match_num,
                    $seededRules[$family]->peer_id_start,
                    $seededRules[$family]->agent_pattern,
                    $seededRules[$family]->agent_match_num,
                    $seededRules[$family]->agent_start,
                ]
            );
        }
        $this->assertTrue($this->clientMatches(
            $seededRules['rTorrent 0.9.8+ (libtorrent)'],
            '-lt0D80-',
            'rtorrent/0.9.8/0.13.8'
        ));
    }

    public function testCacheInvalidationMigrationClearsTheAgentAllowCache(): void
    {
        Cache::put('all_agent_allows:php', ['stale-rule'], 3600);

        $migration = require base_path('database/migrations/2026_11_24_000000_invalidate_agent_allow_cache.php');
        $migration->up();

        $this->assertNull(Cache::get('all_agent_allows:php'));
    }

    public function testBiglybtMigrationFixesTheStoredAgentPatternAndClearsItsCache(): void
    {
        DB::table('agent_allowed_family')->insert([
            'family' => 'BiglyBT 3.x',
            'start_name' => 'BiglyBT 3.0.0.0',
            'agent_pattern' => '/^BiglyBT\\ /3\\.([0-9])\\.([0-9])\\.([0-9])/',
        ]);
        Cache::put('all_agent_allows:php', ['stale-rule'], 3600);

        $migration = require base_path('database/migrations/2026_11_24_010000_fix_biglybt_agent_pattern.php');
        $migration->up();

        $pattern = DB::table('agent_allowed_family')
            ->where('family', 'BiglyBT 3.x')
            ->value('agent_pattern');

        $this->assertSame('/^BiglyBT\\/3\\.([0-9])\\.([0-9])\\.([0-9])/', $pattern);
        $this->assertSame(1, preg_match($pattern, 'BiglyBT/3.0.0.0'));
        $this->assertNull(Cache::get('all_agent_allows:php'));
    }

    private function clientMatches(object $rule, string $peerId, string $agent): bool
    {
        return $this->versionMatches(
            $rule->peer_id_pattern,
            $rule->peer_id_start,
            $rule->peer_id_match_num,
            $peerId,
            $rule->peer_id_matchtype
        ) && $this->versionMatches(
            $rule->agent_pattern,
            $rule->agent_start,
            $rule->agent_match_num,
            $agent,
            $rule->agent_matchtype
        );
    }

    private function versionMatches(string $pattern, string $start, int $matchNum, string $candidate, string $matchType): bool
    {
        if (!preg_match($pattern, $start, $minimum) || !preg_match($pattern, $candidate, $version)) {
            return false;
        }

        for ($group = 1; $group <= $matchNum; $group++) {
            $currentPart = $matchType === 'hex'
                ? hexdec($version[$group] ?? '0')
                : (int)($version[$group] ?? 0);
            $minimumPart = $matchType === 'hex'
                ? hexdec($minimum[$group] ?? '0')
                : (int)($minimum[$group] ?? 0);
            if ($currentPart !== $minimumPart) {
                return $currentPart > $minimumPart;
            }
        }

        return true;
    }
}
