<?php

declare(strict_types=1);

namespace Tests\Unit\SiteImport;

use App\Data\SiteImport\Asset;
use App\Data\SiteImport\Block;
use App\Data\SiteImport\Diagnostic;
use App\Data\SiteImport\Envelope;
use App\Data\SiteImport\Page;
use App\Data\SiteImport\PageData;
use App\Data\SiteImport\SiteSettings;
use App\Data\SiteImport\SocialLinks;
use App\Data\SiteImport\Source;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

// Pins the top-level Envelope contract:
//   1. schemaVersion serialises as an integer `1`.
//   2. All six top-level keys appear in the JSON output.
//   3. Empty payload is still shape-valid (four keys may be empty
//      objects/arrays; pages will be [] on the empty factory even
//      though a real payload requires at least one home page).
//   4. Optional keys on SiteSettings are OMITTED from JSON when unset
//      (Optional sentinel, not null). Sending null for an unset key
//      would be wrong per Contract Part II "Sparse props are correct".
//   5. Round-trip: Envelope → JSON → array preserves the shape.
final class EnvelopeTest extends TestCase
{
    #[Test]
    public function empty_envelope_has_all_six_top_level_keys(): void
    {
        $env = Envelope::emptyShell(new Source(
            url: 'https://www.tbirdhoops.org/',
            scrapedAt: '2026-08-21T12:00:00Z',
            pagesDiscovered: 7,
            pagesMapped: 7,
        ));

        $json = $env->toArray();

        foreach (['schemaVersion', 'source', 'site', 'pages', 'assets', 'diagnostics'] as $key) {
            $this->assertArrayHasKey($key, $json, "Envelope must carry `{$key}`");
        }
    }

    #[Test]
    public function schema_version_serialises_as_integer_one(): void
    {
        $env = Envelope::emptyShell($this->source());
        $this->assertSame(1, $env->toArray()['schemaVersion']);
        $this->assertSame(1, Envelope::SCHEMA_VERSION);
    }

    #[Test]
    public function optional_site_settings_are_omitted_not_null(): void
    {
        // Contract Part II "Sparse props are correct":
        //   "A stored value always wins, INCLUDING an empty string.
        //    Sending `null` for a prop is never correct. Omit the
        //    key instead."
        // An unset SiteSettings field MUST NOT serialise as
        // "primaryColor": null. It must be absent from the object.
        $env = Envelope::emptyShell($this->source());
        $site = $env->toArray()['site'];

        // Empty SiteSettings should serialise to an object with no
        // keys — spatie/laravel-data omits Optional-sentinel fields.
        $this->assertIsArray($site);
        $this->assertArrayNotHasKey('primaryColor', $site);
        $this->assertArrayNotHasKey('neutralColor', $site);
        $this->assertArrayNotHasKey('siteName', $site);
    }

    #[Test]
    public function measured_palette_survives_serialisation(): void
    {
        // The M1 milestone requires primaryColor + neutralColor to
        // round-trip cleanly. Their absence in the JSON would leave
        // the imported site in template-default colours.
        $env = new Envelope(
            schemaVersion: Envelope::SCHEMA_VERSION,
            source: $this->source(),
            site: new SiteSettings(
                siteName: 'Thunderbird & Flight Basketball',
                primaryColor: '#AE292E',
                neutralColor: '#5C5151',
            ),
            pages: new DataCollection(Page::class, []),
            assets: new DataCollection(Asset::class, []),
            diagnostics: new DataCollection(Diagnostic::class, []),
        );

        $site = $env->toArray()['site'];
        $this->assertSame('#AE292E', $site['primaryColor']);
        $this->assertSame('#5C5151', $site['neutralColor']);
        $this->assertSame('Thunderbird & Flight Basketball', $site['siteName']);
        // Fields that were never set stay OUT of the object.
        $this->assertArrayNotHasKey('headerColor', $site);
        $this->assertArrayNotHasKey('siteBackground', $site);
    }

    #[Test]
    public function page_shape_carries_all_required_fields(): void
    {
        $home = new Page(
            id: 'home',
            slug: '',
            title: 'Home',
            parentId: null,
            navOrder: 0,
            showInNav: true,
            data: new PageData(
                content: new DataCollection(Block::class, [
                    new Block(type: 'Hero', props: ['id' => 'hero-abcdef', 'heading' => 'Welcome']),
                ]),
            ),
        );

        $json = $home->toArray();
        foreach (['id', 'slug', 'title', 'parentId', 'navOrder', 'showInNav', 'data'] as $key) {
            $this->assertArrayHasKey($key, $json);
        }

        // parentId serialises as null when null (not omitted) — it's
        // a real value carrying "top-level page".
        $this->assertNull($json['parentId']);
        $this->assertSame('', $json['slug']);
        $this->assertSame(0, $json['navOrder']);
        $this->assertTrue($json['showInNav']);

        // data.root and data.zones are always {} in a valid payload;
        // this DTO's defaults reflect that.
        $this->assertSame([], $json['data']['root']);
        $this->assertSame([], $json['data']['zones']);
        $this->assertCount(1, $json['data']['content']);
        $this->assertSame('Hero', $json['data']['content'][0]['type']);
    }

    #[Test]
    public function asset_optional_fields_are_omitted_when_unset(): void
    {
        $asset = new Asset(
            ref: 'site-logo',
            sourceUrl: 'https://cdn2.sportngin.com/attachments/banner_graphic/aa/siteHeader.png',
            filename: 'siteHeader.png',
            mimeType: 'image/png',
        );
        $json = $asset->toArray();

        $this->assertSame('site-logo', $json['ref']);
        $this->assertArrayNotHasKey('alt', $json);
        $this->assertArrayNotHasKey('usage', $json);
    }

    #[Test]
    public function diagnostic_optional_fields_are_omitted_when_unset(): void
    {
        $d = new Diagnostic(
            severity: 'warning',
            code: 'hero_image_unreachable',
            message: 'hero background_image is no longer available from the source CDN',
        );
        $json = $d->toArray();

        $this->assertSame('warning', $json['severity']);
        $this->assertSame('hero_image_unreachable', $json['code']);
        $this->assertArrayNotHasKey('sourceUrl', $json);
        $this->assertArrayNotHasKey('droppedContent', $json);
    }

    #[Test]
    public function envelope_round_trips_through_json(): void
    {
        $env = new Envelope(
            schemaVersion: Envelope::SCHEMA_VERSION,
            source: $this->source(),
            site: new SiteSettings(
                primaryColor: '#AE292E',
                neutralColor: '#5C5151',
                socialLinks: new SocialLinks(facebook: 'https://facebook.com/tbirdhoops'),
            ),
            pages: new DataCollection(Page::class, [
                new Page(
                    id: 'home',
                    slug: '',
                    title: 'Home',
                    parentId: null,
                    navOrder: 0,
                    showInNav: true,
                    data: new PageData(content: new DataCollection(Block::class, [])),
                ),
            ]),
            assets: new DataCollection(Asset::class, [
                new Asset(
                    ref: 'site-logo',
                    sourceUrl: 'https://cdn2.sportngin.com/attachments/banner_graphic/aa/siteHeader.png',
                    filename: 'siteHeader.png',
                    mimeType: 'image/png',
                    usage: 'logo',
                ),
            ]),
            diagnostics: new DataCollection(Diagnostic::class, []),
        );

        $encoded = json_encode($env->toArray(), JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['schemaVersion']);
        $this->assertSame('#AE292E', $decoded['site']['primaryColor']);
        $this->assertSame('https://facebook.com/tbirdhoops', $decoded['site']['socialLinks']['facebook']);
        $this->assertCount(1, $decoded['pages']);
        $this->assertSame('', $decoded['pages'][0]['slug']);
        $this->assertCount(1, $decoded['assets']);
        $this->assertSame('logo', $decoded['assets'][0]['usage']);
    }

    private function source(): Source
    {
        return new Source(
            url: 'https://www.tbirdhoops.org/',
            scrapedAt: '2026-08-21T12:00:00Z',
            pagesDiscovered: 7,
            pagesMapped: 7,
        );
    }
}
