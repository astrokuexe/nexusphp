<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('faq')) {
            DB::table('faq')->where('categ', 71)->delete();
            DB::table('faq')->whereIn('link_id', [12, 71])->delete();

            $rows = DB::table('faq')->where('answer', 'like', '%aboutnexus.php%')->get(['id', 'answer']);
            foreach ($rows as $row) {
                $answer = preg_replace(
                    '/<a\b[^>]*href=["\']aboutnexus\.php(?:#[^"\']*)?["\'][^>]*>(.*?)<\/a>/is',
                    '$1',
                    $row->answer
                );
                $answer = preg_replace(
                    '/(?:<br\s*\/?>\s*)?[^<]*<a\b[^>]*href=["\']https:\/\/nexusphp\.org\/wiki\/[^"\']*["\'][^>]*>.*?<\/a>\.?/is',
                    '',
                    $answer
                );
                DB::table('faq')->where('id', $row->id)->update(['answer' => $answer]);
            }

            $replacements = [
                'http://www.btfaq.com/' => 'https://www.zdnet.com/article/what-is-torrenting-and-how-does-it-work/',
                'http://www.utorrent.com' => 'https://www.utorrent.com/desktop/',
                'http://deluge-torrent.org' => 'https://deluge-torrent.org/download/',
                'http://www.transmissionbt.com' => 'https://transmissionbt.com/download/',
                'http://libtorrent.rakshasa.no' => 'https://github.com/rakshasa/rtorrent',
                'http://technet.microsoft.com/en-us/library/bb456984.aspx' => 'https://learn.microsoft.com/en-us/previous-versions/windows/it-pro/windows-xp/bb456984(v=technet.10)',
                'http://portforward.com/' => 'https://portforward.com/',
                'http://portforward.com' => 'https://portforward.com/',
                'http://proxyjudge.org' => 'https://proxycheck.io/web',
                'http://www.iana.org/assignments/port-numbers' => 'https://www.iana.org/assignments/service-names-port-numbers/',
            ];
            $rows = DB::table('faq')->where('answer', 'like', '%http://%')->get(['id', 'answer']);
            foreach ($rows as $row) {
                $answer = str_replace(array_keys($replacements), array_values($replacements), $row->answer);
                $answer = str_replace('ProxyJudge', 'ProxyCheck', $answer);
                if ($answer !== $row->answer) {
                    DB::table('faq')->where('id', $row->id)->update(['answer' => $answer]);
                }
            }
        }

        if (Schema::hasTable('rules')) {
            $rules = DB::table('rules')->where('text', 'like', '%nexushd.org%')->get(['id', 'text']);
            foreach ($rules as $rule) {
                $text = preg_replace(
                    '/Please refer to \[url=http:\/\/www\.nexushd\.org\/forums\.php\?action=viewtopic&forumid=13&topicid=2848\].*?guidance\.\s*/is',
                    '',
                    $rule->text
                );
                $text = preg_replace(
                    '/\[url=http:\/\/www\.nexushd\.org\/forums\.php\?action=viewtopic&forumid=13&topicid=2848\](.*?)\[\/url\]/is',
                    '$1',
                    $text
                );
                DB::table('rules')->where('id', $rule->id)->update(['text' => $text]);
            }
        }
    }

    public function down(): void
    {
        // Deleted FAQ entries and migrated URLs cannot be restored safely.
    }
};
