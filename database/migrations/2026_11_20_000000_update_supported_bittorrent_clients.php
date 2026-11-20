<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('agent_allowed_family')) {
            return;
        }

        DB::table('agent_allowed_family')->where('family', 'like', 'uTorrent%')->delete();
        DB::table('agent_allowed_family')->where('family', 'like', 'BitComet%')->delete();
        DB::table('agent_allowed_family')->where('family', 'like', 'qBittorrent%')->delete();
        foreach (['Azureus%', 'BitTorrent%', 'Rufus%', 'Enhanced CTorrent%', 'BitRocket%', 'SymTorrent%'] as $familyPattern) {
            DB::table('agent_allowed_family')->where('family', 'like', $familyPattern)->delete();
        }

        DB::table('agent_allowed_family')->insert([
            [
                'family' => 'uTorrent 2.2.1',
                'start_name' => 'uTorrent 2.2.1',
                'peer_id_pattern' => '/^-UT2210-/',
                'peer_id_match_num' => 0,
                'peer_id_matchtype' => 'dec',
                'peer_id_start' => '-UT2210-',
                'agent_pattern' => '/^uTorrent\\/2210(?:$|[^0-9])/',
                'agent_match_num' => 0,
                'agent_matchtype' => 'dec',
                'agent_start' => 'uTorrent/2210',
                'exception' => 'no',
                'allowhttps' => 'yes',
                'comment' => '',
                'hits' => 0,
            ],
            [
                'family' => 'BitComet 2.21+',
                'start_name' => 'BitComet 2.21',
                'peer_id_pattern' => '/^-BC/',
                'peer_id_match_num' => 0,
                'peer_id_matchtype' => 'dec',
                'peer_id_start' => '-BC',
                'agent_pattern' => '/^BitComet\\/([0-9]+)\\.([0-9]+)/',
                'agent_match_num' => 2,
                'agent_matchtype' => 'dec',
                'agent_start' => 'BitComet/2.21',
                'exception' => 'no',
                'allowhttps' => 'yes',
                'comment' => '',
                'hits' => 0,
            ],
            [
                'family' => 'qBittorrent 4.4.5+',
                'start_name' => 'qBittorrent 4.4.5',
                'peer_id_pattern' => '/^-qB/',
                'peer_id_match_num' => 0,
                'peer_id_matchtype' => 'dec',
                'peer_id_start' => '-qB',
                'agent_pattern' => '/^qBittorrent\\/([0-9]+)\\.([0-9]+)\\.([0-9]+)/',
                'agent_match_num' => 3,
                'agent_matchtype' => 'dec',
                'agent_start' => 'qBittorrent/4.4.5',
                'exception' => 'no',
                'allowhttps' => 'yes',
                'comment' => '',
                'hits' => 0,
            ],
        ]);
        $this->removeUnsupportedClientsFromFaq();
        \Nexus\Database\NexusDB::cache_del('allowed_client_list');
    }

    public function down(): void
    {
        if (!Schema::hasTable('agent_allowed_family')) {
            return;
        }

        DB::table('agent_allowed_family')->where('family', 'like', 'uTorrent%')->delete();
        DB::table('agent_allowed_family')->where('family', 'like', 'BitComet%')->delete();
        DB::table('agent_allowed_family')->where('family', 'like', 'qBittorrent%')->delete();

        DB::table('agent_allowed_family')->insert([
            [
                'family' => 'uTorrent 2.x.x',
                'start_name' => 'uTorrent 2.0(build 17624)',
                'peer_id_pattern' => '/^-UT2([0-9])([0-9])([0-9])-/',
                'peer_id_match_num' => 3,
                'peer_id_matchtype' => 'dec',
                'peer_id_start' => '-UT2000-',
                'agent_pattern' => '/^uTorrent\\/2([0-9])([0-9])([0-9])/',
                'agent_match_num' => 3,
                'agent_matchtype' => 'dec',
                'agent_start' => 'uTorrent/2000',
                'exception' => 'no',
                'allowhttps' => 'yes',
                'comment' => '',
                'hits' => 0,
            ],
            [
                'family' => 'uTorrent 3.x',
                'start_name' => 'uTorrent/3000',
                'peer_id_pattern' => '/^-UT3([0-9])([0-9])([0-9])-/',
                'peer_id_match_num' => 3,
                'peer_id_matchtype' => 'dec',
                'peer_id_start' => '-UT3000-',
                'agent_pattern' => '/^uTorrent\\/3([0-9])([0-9])([0-9])/',
                'agent_match_num' => 3,
                'agent_matchtype' => 'dec',
                'agent_start' => 'uTorrent/3000',
                'exception' => 'no',
                'allowhttps' => 'yes',
                'comment' => '',
                'hits' => 0,
            ],
            [
                'family' => 'uTorrent 3.x',
                'start_name' => 'uTorrent',
                'peer_id_pattern' => '',
                'peer_id_match_num' => 0,
                'peer_id_matchtype' => 'dec',
                'peer_id_start' => '-UT355W-',
                'agent_pattern' => '/^uTorrent/',
                'agent_match_num' => 0,
                'agent_matchtype' => 'dec',
                'agent_start' => 'uTorrent',
                'exception' => 'no',
                'allowhttps' => 'yes',
                'comment' => '',
                'hits' => 6,
            ],
            [
                'family' => 'qBittorrent 4.x',
                'start_name' => 'qBittorrent 4.0.0',
                'peer_id_pattern' => '/^-qB4([0-9])([0-9])/',
                'peer_id_match_num' => 2,
                'peer_id_matchtype' => 'dec',
                'peer_id_start' => '-qB400',
                'agent_pattern' => '/^qBittorrent\\/4\\.([0-9])\\.([0-9])/',
                'agent_match_num' => 2,
                'agent_matchtype' => 'dec',
                'agent_start' => 'qBittorrent/4.0.0',
                'exception' => 'no',
                'allowhttps' => 'yes',
                'comment' => '',
                'hits' => 3,
            ],
            [
                'family' => 'qBittorrent 5.x',
                'start_name' => 'qBittorrent 5.0.0',
                'peer_id_pattern' => '/^-qB5([0-9])([0-9])/',
                'peer_id_match_num' => 2,
                'peer_id_matchtype' => 'dec',
                'peer_id_start' => '-qB500',
                'agent_pattern' => '/^qBittorrent\\/5\\.([0-9])\\.([0-9])/',
                'agent_match_num' => 2,
                'agent_matchtype' => 'dec',
                'agent_start' => 'qBittorrent/5.0.0',
                'exception' => 'no',
                'allowhttps' => 'yes',
                'comment' => '',
                'hits' => 0,
            ],
        ]);
        \Nexus\Database\NexusDB::cache_del('allowed_client_list');
    }

    private function removeUnsupportedClientsFromFaq(): void
    {
        if (!Schema::hasTable('faq')) {
            return;
        }

        foreach (DB::table('faq')->where('link_id', 29)->get(['id', 'answer']) as $faq) {
            $answer = preg_replace(
                '/<li><a class="faqlink" href="[^"]+">(Azureus|BitTorrent|Rufus|Enhanced CTorrent|BitRocket)<\/a>:[^<]*<\/li>\s*/i',
                '',
                $faq->answer
            );
            $answer = preg_replace(
                '/<b>Symbian[^<]*<\/b>\s*<ul>\s*<li><a class="faqlink" href="[^"]+">SymTorrent<\/a>:[^<]*<\/li>\s*<\/ul>\s*/i',
                '',
                $answer
            );
            $answer = preg_replace('/<li>Rufus:[^<]*<\/li>\s*/i', '', $answer);
            $answer = str_replace(
                [
                    '，如Azureus 2.0.7.0或更高版本，',
                    ', like Azureus 2.0.7.0 or higher,',
                    '为了从本站得到最好的下载体验,我们高度推荐<a class="faqlink" href="http://www.utorrent.com/download.php">uTorrent</a> 以及<a class="faqlink" href="http://azureus.sourceforge.net/download.php">Azureus</a>这两个软件的最新稳定版。',
                    '為了從本站得到最好的下載體驗,我們高度建議<a class="faqlink" href="http://www.utorrent.com/download.php">uTorrent</a> 以及<a class="faqlink" href="http://azureus.sourceforge.net/download.php">Azureus</a>這兩個軟體的最新穩定版。',
                    'Please do not use any beta or testing version of bittorrent clients, e.g. uTorrent 1.8.0B. To get the best downloading experience, we highly recommend latest stable version of <a class="faqlink" href="http://www.utorrent.com/download.php">uTorrent</a> and <a class="faqlink" href="http://azureus.sourceforge.net/download.php">Azureus</a>.',
                ],
                [
                    '，',
                    '',
                    '为了从本站得到最好的下载体验,我们高度推荐<a class="faqlink" href="https://www.qbittorrent.org">qBittorrent 4.4.5及后续版本</a>。',
                    '為了從本站得到最好的下載體驗,我們高度建議<a class="faqlink" href="https://www.qbittorrent.org">qBittorrent 4.4.5及後續版本</a>。',
                    'Please do not use any beta or testing version of bittorrent clients, e.g. uTorrent 1.8.0B. To get the best downloading experience, we recommend <a class="faqlink" href="https://www.qbittorrent.org">qBittorrent 4.4.5 and later</a>.',
                ],
                $answer
            );

            if (!is_string($answer)) {
                throw new \RuntimeException("Could not update FAQ entry {$faq->id}.");
            }

            if ($answer !== $faq->answer) {
                DB::table('faq')->where('id', $faq->id)->update(['answer' => $answer]);
            }
        }
    }
};
