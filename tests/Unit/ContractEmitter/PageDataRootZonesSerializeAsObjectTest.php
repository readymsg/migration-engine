<?php

declare(strict_types=1);

namespace Tests\Unit\ContractEmitter;

use App\Data\SiteImport\Block;
use App\Data\SiteImport\Envelope;
use App\Data\SiteImport\Page;
use App\Data\SiteImport\PageData;
use App\Data\SiteImport\SiteSettings;
use App\Data\SiteImport\Source;
use App\Services\ContractEmitter\ContractSchema;
use App\Services\ContractEmitter\EnvelopeJson;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

// Load-bearing test for the v1-pre-existing bug called out by the
// contract-v2 orientation: PageData's root/zones typed `array`
// serialised as JSON `[]`, not `{}`. Both v1 and v2 schemas require
// JSON object shape (`$defs/pageData.type: object,
// maxProperties: 0`). The internal validator's `!== []` check
// operated on the PHP value and passed, but the shipped payload
// failed schema validation. EnvelopeJson::encode closes the seam.
//
// We assert on the JSON STRING round-trip (not the PHP array), so
// this test would have caught the bug historically.
final class PageDataRootZonesSerializeAsObjectTest extends TestCase
{
    #[Test]
    public function empty_root_and_zones_serialize_as_json_objects(): void
    {
        $envelope = new Envelope(
            schemaVersion: 1,
            source: new Source(
                url: 'https://example.com',
                scrapedAt: '2026-08-25T00:00:00Z',
                pagesDiscovered: 1,
                pagesMapped: 1,
            ),
            site: new SiteSettings,
            pages: new DataCollection(Page::class, [
                new Page(
                    id: 'home',
                    slug: '',
                    title: 'Home',
                    parentId: null,
                    navOrder: 0,
                    showInNav: true,
                    data: new PageData(
                        content: new DataCollection(Block::class, []),
                    ),
                ),
            ]),
            assets: new DataCollection(\App\Data\SiteImport\Asset::class, []),
        );

        // Encode via the single-source helper.
        $json = EnvelopeJson::encode($envelope, pretty: false);

        // JSON string must contain the object literals, NOT the
        // array literals. This is the concrete on-wire behaviour;
        // asserting on the string catches spatie regressions that
        // would emit `[]` again.
        self::assertStringContainsString('"root":{}', $json);
        self::assertStringContainsString('"zones":{}', $json);
        self::assertStringNotContainsString('"root":[]', $json);
        self::assertStringNotContainsString('"zones":[]', $json);

        // Belt-and-braces: decode with objects (not assoc arrays)
        // and confirm root/zones are objects on the decoded shape.
        $decoded = EnvelopeJson::decodeObject($json);
        self::assertIsObject($decoded);
        self::assertIsObject($decoded->pages[0]->data->root);
        self::assertIsObject($decoded->pages[0]->data->zones);
    }

    #[Test]
    public function encode_array_wraps_root_zones_as_stdclass(): void
    {
        // Direct check on the pre-JSON structure so a future consumer
        // that reads `encodeArray()` without going through `encode()`
        // still sees the object shape.
        $envelope = new Envelope(
            schemaVersion: 1,
            source: new Source(
                url: 'https://example.com',
                scrapedAt: '2026-08-25T00:00:00Z',
                pagesDiscovered: 1,
                pagesMapped: 1,
            ),
            site: new SiteSettings,
            pages: new DataCollection(Page::class, [
                new Page(
                    id: 'home',
                    slug: '',
                    title: 'Home',
                    parentId: null,
                    navOrder: 0,
                    showInNav: true,
                    data: new PageData(
                        content: new DataCollection(Block::class, []),
                    ),
                ),
            ]),
            assets: new DataCollection(\App\Data\SiteImport\Asset::class, []),
        );

        $arr = EnvelopeJson::encodeArray($envelope);
        self::assertIsObject($arr['pages'][0]['data']['root']);
        self::assertIsObject($arr['pages'][0]['data']['zones']);
    }

    #[Test]
    public function emitted_json_string_passes_opis_schema_validation_for_pageData_root_zones(): void
    {
        // The load-bearing round-trip: encode an envelope with the
        // default empty root/zones, round-trip through the on-wire
        // JSON string (what actually ships to TeamLinkt), decode via
        // the opis-shaped decoder, and run the real opis validator
        // against the contract schema. A regression that re-emits
        // `[]` would fail $defs/pageData's `maxProperties: 0 /
        // type: object` here — not just a string-literal check.
        // One site setting present so the `site` field serialises
        // as a non-empty JSON object; keeps this test focused on
        // the pages[].data.root/zones round-trip and avoids the
        // separate empty-site serialisation question (see
        // SiteSettings — every key is Optional, and a fully-empty
        // $site would currently serialise as JSON `[]`).
        $envelope = new Envelope(
            schemaVersion: 1,
            source: new Source(
                url: 'https://example.com',
                scrapedAt: '2026-08-25T00:00:00Z',
                pagesDiscovered: 1,
                pagesMapped: 1,
            ),
            site: new SiteSettings(siteName: 'Example'),
            pages: new DataCollection(Page::class, [
                new Page(
                    id: 'home',
                    slug: '',
                    title: 'Home',
                    parentId: null,
                    navOrder: 0,
                    showInNav: true,
                    data: new PageData(
                        content: new DataCollection(Block::class, []),
                    ),
                ),
            ]),
            assets: new DataCollection(\App\Data\SiteImport\Asset::class, []),
        );

        // ONE encode, then run opis against the DECODED STRING
        // (NOT the DTO). This is the full on-wire round-trip.
        $json = EnvelopeJson::encode($envelope, pretty: false);
        $decoded = EnvelopeJson::decodeObject($json);

        $schema = ContractSchema::load();
        $schemaObject = EnvelopeJson::decodeObject($schema->rawJson());

        $result = (new Validator)->validate($decoded, $schemaObject);

        // If opis reports an error, dump it in the failure message so
        // a future regression tells you WHICH keyword tripped (likely
        // `maxProperties` or `type` under `pages[0].data.root/zones`
        // if root/zones went back to JSON `[]`).
        if (! $result->isValid()) {
            $err = $result->error();
            $msg = $err === null ? 'unknown' : json_encode(
                (new \Opis\JsonSchema\Errors\ErrorFormatter)->format($err, multiple: true),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            );
            self::fail("Emitted JSON string failed opis schema validation:\n{$msg}");
        }

        self::assertTrue($result->isValid());
    }
}
