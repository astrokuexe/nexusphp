<?php

namespace Tests\Unit;

use App\Services\TorrentExternalMetadata;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TorrentExternalMetadataTest extends TestCase
{
    public function testFetchesMetadataOnlyForExplicitExternalReferences(): void
    {
        config([
            'cache.default' => 'array',
            'services.myanimelist.client_id' => 'test-client-id',
            'services.anidb.client_name' => 'test-client',
            'services.anidb.client_version' => '1',
            'services.lost_media_wiki.api_url' => 'https://lostmediawiki.com/api.php',
        ]);
        Http::fake([
            'api.myanimelist.net/v2/anime/5114*' => Http::response([
                'id' => 5114,
                'title' => 'Fullmetal Alchemist: Brotherhood',
                'mean' => 9.1,
                'num_episodes' => 64,
                'genres' => [['name' => 'Action']],
            ]),
            'api.anidb.net:9001/httpapi*' => Http::response(
                '<anime><titles><title>Example Anime</title></titles><type>TV Series</type><episodes>12</episodes></anime>',
                200,
                ['Content-Type' => 'application/xml']
            ),
            'lostmediawiki.com/api.php*' => Http::response([
                'query' => [
                    'pages' => [
                        '42' => [
                            'pageid' => 42,
                            'title' => 'Example Lost Media',
                            'extract' => 'An example article summary.',
                        ],
                    ],
                ],
            ]),
        ]);

        $metadata = app(TorrentExternalMetadata::class)->fetchForTorrent([
            'pt_gen' => json_encode([
                'scraper_import' => [
                    'myanimelist' => 'https://myanimelist.net/anime/5114',
                    'anidb' => 'https://anidb.net/anime/12345',
                    'lost_media_wiki' => 'https://lostmediawiki.com/wiki/Example_Lost_Media',
                ],
            ]),
        ]);

        $this->assertSame('Fullmetal Alchemist: Brotherhood', $metadata['myanimelist'][0]['title']);
        $this->assertSame(64, $metadata['myanimelist'][0]['episodes']);
        $this->assertSame('Example Anime', $metadata['anidb'][0]['title']);
        $this->assertSame('Example Lost Media', $metadata['lost_media_wiki'][0]['title']);
        $this->assertSame('An example article summary.', $metadata['lost_media_wiki'][0]['synopsis']);
        Http::assertSentCount(3);
    }

    public function testAutomaticallyDetectsMetadataFromMyAnimeListLinkField(): void
    {
        config([
            'cache.default' => 'array',
            'services.myanimelist.client_id' => 'test-client-id',
        ]);
        Http::fake([
            'api.myanimelist.net/v2/anime/5114*' => Http::response([
                'id' => 5114,
                'title' => 'Fullmetal Alchemist: Brotherhood',
            ]),
        ]);

        $metadata = app(TorrentExternalMetadata::class)->fetchForTorrent([
            'pt_gen' => json_encode([
                'myanimelist_link' => 'https://myanimelist.net/anime/5114',
            ]),
        ]);

        $this->assertSame('Fullmetal Alchemist: Brotherhood', $metadata['myanimelist'][0]['title']);
        Http::assertSentCount(1);
    }

    public function testDoesNotSearchUsingTorrentTitleOrFetchWithoutReferences(): void
    {
        config([
            'cache.default' => 'array',
            'services.myanimelist.client_id' => null,
            'services.anidb.client_name' => null,
            'services.anidb.client_version' => null,
        ]);
        Http::fake();

        $metadata = app(TorrentExternalMetadata::class)->fetchForTorrent([
            'name' => 'An anime title without source identifiers',
            'description' => 'No external metadata links here.',
        ]);

        $this->assertSame([], $metadata['myanimelist']);
        $this->assertSame([], $metadata['anidb']);
        $this->assertSame([], $metadata['lost_media_wiki']);
        Http::assertNothingSent();
    }

    public function testUsesExplicitIdentifiersEmbeddedInMetadata(): void
    {
        config([
            'cache.default' => 'array',
            'services.myanimelist.client_id' => 'test-client-id',
            'services.anidb.client_name' => null,
            'services.anidb.client_version' => null,
        ]);
        Http::fake([
            'api.myanimelist.net/v2/anime/6306*' => Http::response([
                'id' => 6306,
                'title' => 'Boku no Hero Academia',
            ]),
        ]);

        $metadata = app(TorrentExternalMetadata::class)->fetchForTorrent([
            'pt_gen' => [
                'myanimelist' => [
                    'id' => 6306,
                    'num_episodes' => 13,
                ],
            ],
        ]);

        $this->assertSame('Boku no Hero Academia', $metadata['myanimelist'][0]['title']);
        $this->assertCount(1, $metadata['myanimelist']);
        Http::assertSentCount(1);
    }
}
