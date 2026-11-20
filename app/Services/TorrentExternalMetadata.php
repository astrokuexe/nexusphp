<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;

class TorrentExternalMetadata
{
    private const CACHE_TTL_MINUTES = 1440;
    private const FAILURE_CACHE_TTL_MINUTES = 5;

    public function fetchForTorrent(array $metadata): array
    {
        $values = [];
        $this->collectStrings($metadata, $values);
        $values = array_slice(array_unique($values), 0, 100);

        return [
            'myanimelist' => $this->fetchAnimeRecords(
                $this->findIds(
                    '~(?:^|[^\w.-])(?:https?://)?(?:www\.)?myanimelist\.net/anime/([0-9]+)(?:[/?#\s"\'<>]|$)~i',
                    '/\b(?:MyAnimeList|MAL)(?:\s+ID)?\s*[:#]\s*([0-9]+)\b/i',
                    ['myanimelist', 'myanimelist_id', 'mal', 'mal_id', 'malid'],
                    $values,
                    $metadata
                ),
                fn (string $id): ?array => $this->fetchMyAnimeList($id)
            ),
            'anidb' => $this->fetchAnimeRecords(
                $this->findIds(
                    '~(?:^|[^\w.-])(?:https?://)?(?:www\.)?anidb\.net/anime/([0-9]+)(?:[/?#\s"\'<>]|$)~i',
                    '/\bAniDB(?:\s+ID)?\s*[:#]\s*([0-9]+)\b/i',
                    ['anidb', 'anidb_id', 'aid'],
                    $values,
                    $metadata
                ),
                fn (string $id): ?array => $this->fetchAniDB($id)
            ),
            'lost_media_wiki' => $this->fetchWikiRecords(
                $this->findWikiTitles($values)
            ),
        ];
    }

    private function fetchAnimeRecords(array $ids, callable $fetch): array
    {
        $records = [];
        foreach (array_slice($ids, 0, 3) as $id) {
            $record = $fetch($id);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    private function fetchMyAnimeList(string $id): ?array
    {
        $clientId = config('services.myanimelist.client_id');
        if (!is_string($clientId) || $clientId === '') {
            return null;
        }

        return $this->remember("torrent_metadata:myanimelist:$id", function () use ($id, $clientId) {
            try {
                $response = Http::acceptJson()
                    ->withUserAgent('NexusPHP torrent metadata')
                    ->withHeader('X-MAL-CLIENT-ID', $clientId)
                    ->connectTimeout(2)
                    ->timeout(4)
                    ->get("https://api.myanimelist.net/v2/anime/$id", [
                        'fields' => 'id,title,main_picture,synopsis,mean,media_type,status,num_episodes,start_date,end_date,genres,studios',
                    ]);
            } catch (ConnectionException $exception) {
                Log::warning('MyAnimeList metadata request failed', ['id' => $id, 'error' => $exception->getMessage()]);
                return false;
            }

            if ($response->failed() || !is_array($data = $response->json())) {
                Log::warning('MyAnimeList returned an invalid metadata response', ['id' => $id, 'status' => $response->status()]);
                return false;
            }

            $genres = is_array($data['genres'] ?? null) ? array_column($data['genres'], 'name') : [];
            $studios = is_array($data['studios'] ?? null) ? array_column($data['studios'], 'name') : [];

            return [
                'id' => (string)($data['id'] ?? $id),
                'title' => (string)($data['title'] ?? ''),
                'url' => "https://myanimelist.net/anime/$id",
                'synopsis' => (string)($data['synopsis'] ?? ''),
                'score' => $data['mean'] ?? null,
                'type' => (string)($data['media_type'] ?? ''),
                'status' => (string)($data['status'] ?? ''),
                'episodes' => $data['num_episodes'] ?? null,
                'start_date' => (string)($data['start_date'] ?? ''),
                'end_date' => (string)($data['end_date'] ?? ''),
                'genres' => $genres,
                'studios' => $studios,
            ];
        });
    }

    private function fetchAniDB(string $id): ?array
    {
        $clientName = config('services.anidb.client_name');
        $clientVersion = config('services.anidb.client_version');
        if (!is_string($clientName) || $clientName === '' || !is_string($clientVersion) || $clientVersion === '') {
            return null;
        }

        return $this->remember("torrent_metadata:anidb:$id", function () use ($id, $clientName, $clientVersion) {
            try {
                $response = Http::connectTimeout(2)
                    ->timeout(4)
                    ->get('http://api.anidb.net:9001/httpapi', [
                        'request' => 'anime',
                        'client' => $clientName,
                        'clientver' => $clientVersion,
                        'protover' => 1,
                        'aid' => $id,
                    ]);
            } catch (ConnectionException $exception) {
                Log::warning('AniDB metadata request failed', ['id' => $id, 'error' => $exception->getMessage()]);
                return false;
            }

            if ($response->failed() || !($anime = @simplexml_load_string(
                $response->body(),
                SimpleXMLElement::class,
                LIBXML_NONET | LIBXML_NOCDATA
            )) || isset($anime->error)) {
                Log::warning('AniDB returned an invalid metadata response', ['id' => $id, 'status' => $response->status()]);
                return false;
            }

            $titles = [];
            foreach ($anime->titles->title ?? [] as $title) {
                $titles[] = trim((string)$title);
            }

            return [
                'id' => $id,
                'title' => $titles[0] ?? '',
                'url' => "https://anidb.net/anime/$id",
                'synopsis' => trim((string)($anime->description ?? '')),
                'type' => trim((string)($anime->type ?? '')),
                'episodes' => isset($anime->episodes) ? (int)$anime->episodes : null,
                'start_date' => trim((string)($anime->startdate ?? '')),
                'end_date' => trim((string)($anime->enddate ?? '')),
            ];
        });
    }

    private function fetchWikiRecords(array $titles): array
    {
        $records = [];
        foreach (array_slice($titles, 0, 3) as $title) {
            $record = $this->fetchLostMediaWiki($title);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    private function fetchLostMediaWiki(string $title): ?array
    {
        $apiUrl = config('services.lost_media_wiki.api_url');
        if (!is_string($apiUrl) || $apiUrl === '') {
            return null;
        }

        return $this->remember('torrent_metadata:lost_media_wiki:' . sha1($title), function () use ($title, $apiUrl) {
            try {
                $response = Http::acceptJson()
                    ->withUserAgent('NexusPHP torrent metadata')
                    ->connectTimeout(2)
                    ->timeout(4)
                    ->get($apiUrl, [
                        'action' => 'query',
                        'format' => 'json',
                        'prop' => 'extracts|pageimages',
                        'exintro' => 1,
                        'explaintext' => 1,
                        'piprop' => 'thumbnail',
                        'pithumbsize' => 240,
                        'titles' => $title,
                    ]);
            } catch (ConnectionException $exception) {
                Log::warning('Lost Media Wiki metadata request failed', ['title' => $title, 'error' => $exception->getMessage()]);
                return false;
            }

            if ($response->failed() || !is_array($data = $response->json())) {
                Log::warning('Lost Media Wiki returned an invalid metadata response', ['title' => $title, 'status' => $response->status()]);
                return false;
            }

            $pages = $data['query']['pages'] ?? [];
            $page = is_array($pages) ? reset($pages) : false;
            if (!is_array($page) || isset($page['missing'])) {
                return false;
            }

            $pageTitle = (string)($page['title'] ?? $title);
            return [
                'id' => $pageTitle,
                'title' => $pageTitle,
                'url' => 'https://lostmediawiki.com/wiki/' . str_replace('%20', '_', rawurlencode(str_replace(' ', '_', $pageTitle))),
                'synopsis' => (string)($page['extract'] ?? ''),
            ];
        });
    }

    private function remember(string $key, callable $callback): ?array
    {
        if (Cache::has($key)) {
            $cached = Cache::get($key);
            return is_array($cached) ? $cached : null;
        }

        $result = $callback();
        Cache::put(
            $key,
            $result,
            now()->addMinutes($result === false ? self::FAILURE_CACHE_TTL_MINUTES : self::CACHE_TTL_MINUTES)
        );

        return is_array($result) ? $result : null;
    }

    private function findIds(string $urlPattern, string $labelPattern, array $fieldNames, array $values, array $metadata): array
    {
        $ids = [];
        foreach ($values as $value) {
            $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
            foreach ([$urlPattern, $labelPattern] as $pattern) {
                if (preg_match_all($pattern, $value, $matches)) {
                    $ids = array_merge($ids, $matches[1]);
                }
            }
        }

        $this->collectNamedIds($metadata, array_fill_keys($fieldNames, true), false, $ids);
        return array_values(array_unique($ids));
    }

    private function collectNamedIds($value, array $fieldNames, bool $isSource, array &$ids): void
    {
        if (!is_array($value)) {
            return;
        }

        foreach ($value as $key => $item) {
            $key = strtolower((string)$key);
            $itemIsSource = $isSource || isset($fieldNames[$key]);
            $isIdField = isset($fieldNames[$key]) || ($isSource && in_array($key, ['id', 'anime_id'], true));
            if ($isIdField && (is_int($item) || (is_string($item) && ctype_digit($item)))) {
                $ids[] = (string)$item;
                continue;
            }
            $this->collectNamedIds($item, $fieldNames, $itemIsSource, $ids);
        }
    }

    private function findWikiTitles(array $values): array
    {
        $titles = [];
        foreach ($values as $value) {
            $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
            if (preg_match_all('~(?:^|[^\w.-])(?:https?://)?(?:www\.)?lostmediawiki\.com/wiki/([^/?#\s<>"\']+)~i', $value, $matches)) {
                foreach ($matches[1] as $slug) {
                    $title = str_replace('_', ' ', rawurldecode($slug));
                    if ($title !== '') {
                        $titles[] = $title;
                    }
                }
            }
        }

        return array_values(array_unique($titles));
    }

    private function collectStrings($value, array &$strings): void
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $this->collectStrings($decoded, $strings);
                return;
            }
            $strings[] = $value;
            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $item) {
            $this->collectStrings($item, $strings);
        }
    }
}
