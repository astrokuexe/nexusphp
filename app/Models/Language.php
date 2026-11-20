<?php

namespace App\Models;

use Nexus\Database\NexusDB;

class Language extends NexusModel
{
    const DEFAULT_ENABLED = ['en'];

    const TRANS_STATE_UP_TO_DATE = 'up-to-date';
    const TRANS_STATE_OUT_DATE = 'outdate';
    const TRANS_STATE_INCOMPLETE = 'incomplete';
    const TRANS_STATE_NEED_NEW = 'need-new';
    const TRANS_STATE_UNAVAILABLE = 'unavailable';

    const CONFIG = [
        'en' => [
            'lang_name' => 'English (US)',
            'trans_state' => self::TRANS_STATE_UP_TO_DATE,
        ],
        'ja' => [
            'lang_name' => '日本語',
            'trans_state' => self::TRANS_STATE_NEED_NEW,
        ],
        'cs' => [
            'lang_name' => 'Czech',
            'trans_state' => self::TRANS_STATE_NEED_NEW,
        ],
        'da' => [
            'lang_name' => 'Danish',
            'trans_state' => self::TRANS_STATE_NEED_NEW,
        ],
        'nl' => [
            'lang_name' => 'Dutch',
            'trans_state' => self::TRANS_STATE_NEED_NEW,
        ],
        'fi' => [
            'lang_name' => 'Finnish',
            'trans_state' => self::TRANS_STATE_NEED_NEW,
        ],
        'fr' => [
            'lang_name' => 'French',
            'trans_state' => self::TRANS_STATE_NEED_NEW,
        ],
        'de' => [
            'lang_name' => 'German',
            'trans_state' => self::TRANS_STATE_NEED_NEW,
        ],
        'el' => [
            'lang_name' => 'Greek',
            'trans_state' => self::TRANS_STATE_NEED_NEW,
        ],
        'nb' => [
            'lang_name' => 'Norwegian',
            'trans_state' => self::TRANS_STATE_NEED_NEW,
        ],
        'pl' => [
            'lang_name' => 'Polish',
            'trans_state' => self::TRANS_STATE_NEED_NEW,
        ],
        'pt' => [
            'lang_name' => 'Portuguese',
            'trans_state' => self::TRANS_STATE_NEED_NEW,
        ],
        'ro' => [
            'lang_name' => 'Romanian',
            'trans_state' => self::TRANS_STATE_NEED_NEW,
        ],
        'ru' => [
            'lang_name' => 'Russian',
            'trans_state' => self::TRANS_STATE_NEED_NEW,
        ],
        'es' => [
            'lang_name' => 'Spanish',
            'trans_state' => self::TRANS_STATE_NEED_NEW,
        ],
        'sv' => [
            'lang_name' => 'Swedish',
            'trans_state' => self::TRANS_STATE_NEED_NEW,
        ],
    ];

    protected $table = 'language';

    protected $fillable = [
        'lang_name', 'site_lang_folder',
    ];

    public static function listAvailable(): array
    {
        $result = [];
        foreach (self::CONFIG as $locale => $info) {
            if ($info['trans_state'] != self::TRANS_STATE_UNAVAILABLE) {
                $result[] = $locale;
            }
        }
        return $result;
    }


    public static function listEnabled($withoutCache = false)
    {
        if ($withoutCache) {
            return Setting::getFromDb('main.site_language_enabled', self::DEFAULT_ENABLED);
        }
        return Setting::get('main.site_language_enabled', self::DEFAULT_ENABLED);
    }

    public static function updateTransStatus(): void
    {
        foreach (self::CONFIG as $locale => $info) {
            self::query()->where('lang_name', $info['lang_name'])->update([
                'site_lang_folder' => $locale,
                'site_lang' => 1,
                'trans_state' => $info['trans_state'],
            ]);
        }
        NexusDB::cache_del("site_lang_lang_list");
    }
}
