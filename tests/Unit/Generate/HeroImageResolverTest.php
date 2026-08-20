<?php

declare(strict_types=1);

namespace Tests\Unit\Generate;

use App\Data\AssemblyFailure;
use App\Data\AssemblyResult;
use App\Data\AssemblyStatus;
use App\Data\GlobalStyleBrief;
use App\Data\NavItem;
use App\Data\PuckOutput;
use App\Data\ScrubKind;
use App\Services\Generate\HeroImageResolver;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

// HeroImageResolver — deterministic pass that overrides block-fill's
// first-image Hero pick when a banner-shape URL exists on the page.
// Invariants:
//   1. When block-fill's current pick already matches banner-shape,
//      keep it and record "kept" reason.
//   2. When block-fill picked a non-banner URL but a banner-shape
//      URL exists in the source, replace and record "replaced"
//      reason.
//   3. When no banner-shape signal exists anywhere, keep block-fill's
//      pick (or fall to first-source-image if block-fill had none)
//      and record the fallback reason.
//   4. Every Hero block gets EXACTLY ONE HeroImageChosen entry —
//      the decision is never invisible, even in the "kept" case.
final class HeroImageResolverTest extends TestCase
{
    private function resolver(): HeroImageResolver
    {
        return new HeroImageResolver;
    }

    private function assemblyWith(PuckOutput $page): AssemblyResult
    {
        return new AssemblyResult(
            pages: new DataCollection(PuckOutput::class, [$page]),
            failures: new DataCollection(AssemblyFailure::class, []),
            block_issues_by_slug: [],
            status: AssemblyStatus::Complete,
            style_brief: new GlobalStyleBrief(
                brand_voice: '',
                palette: [],
                layout_conventions: [],
                nav: new DataCollection(NavItem::class, []),
            ),
        );
    }

    #[Test]
    public function keeps_block_fill_pick_when_it_already_matches_banner_shape(): void
    {
        $url = 'https://cdn4.sportngin.com/attachments/photo/64f2/LTYB_site-banner_large.jpg';
        $page = new PuckOutput(
            page_slug: 'home',
            content: [
                ['type' => 'Hero', 'props' => ['heading' => 'W', 'background_image' => $url]],
            ],
            root: ['title' => 'Home'],
        );
        $md = "![]($url)\n\nBody...";
        $out = $this->resolver()->run($this->assemblyWith($page), ['home' => $md]);
        $updated = $out->pages->items()[0];
        $this->assertSame($url, $updated->content[0]['props']['background_image']);

        $issues = $out->scrub_issues_by_slug['home'];
        $this->assertCount(1, $issues);
        $this->assertSame(ScrubKind::HeroImageChosen, $issues[0]->kind);
        $this->assertStringContainsString('kept block-fill pick', $issues[0]->reason);
        $this->assertStringContainsString('banner-shape rule', $issues[0]->reason);
    }

    #[Test]
    public function replaces_block_fill_pick_with_banner_shape_candidate(): void
    {
        $firstImage = 'https://cdn1.sportngin.com/attachments/photo/aa/news_thumbnail.jpg';
        $bannerImage = 'https://cdn2.sportngin.com/attachments/banner_graphic/bb/siteHeader.png';
        // Block-fill picked the first image (news thumbnail); source
        // markdown has a banner further down.
        $page = new PuckOutput(
            page_slug: 'home',
            content: [
                ['type' => 'Hero', 'props' => ['heading' => 'W', 'background_image' => $firstImage]],
            ],
            root: ['title' => 'Home'],
        );
        $md = "![]($firstImage)\n\nSome content\n\n![]($bannerImage)\n";
        $out = $this->resolver()->run($this->assemblyWith($page), ['home' => $md]);
        $updated = $out->pages->items()[0];
        $this->assertSame(
            $bannerImage,
            $updated->content[0]['props']['background_image'],
            'must replace news-thumb pick with banner_graphic URL',
        );

        $issues = $out->scrub_issues_by_slug['home'];
        $this->assertCount(1, $issues);
        $this->assertStringContainsString('replaced block-fill', $issues[0]->reason);
    }

    #[Test]
    public function keeps_block_fill_pick_when_no_banner_signal_exists(): void
    {
        $firstImage = 'https://cdn1.sportngin.com/attachments/photo/aa/img_0001.jpg';
        $second = 'https://cdn2.sportngin.com/attachments/photo/bb/img_0002.jpg';
        $page = new PuckOutput(
            page_slug: 'home',
            content: [
                ['type' => 'Hero', 'props' => ['heading' => 'W', 'background_image' => $firstImage]],
            ],
            root: ['title' => 'Home'],
        );
        $md = "![]($firstImage)\n\n![]($second)\n";
        $out = $this->resolver()->run($this->assemblyWith($page), ['home' => $md]);
        $updated = $out->pages->items()[0];
        $this->assertSame($firstImage, $updated->content[0]['props']['background_image']);

        $issues = $out->scrub_issues_by_slug['home'];
        $this->assertCount(1, $issues);
        $this->assertStringContainsString('no banner-shape signal', $issues[0]->reason);
    }

    #[Test]
    public function every_hero_gets_exactly_one_recorded_decision(): void
    {
        // Two pages, each with a Hero. Both should record one
        // decision — the "kept" case is NOT silent.
        $urlA = 'https://cdn1.sportngin.com/attachments/photo/aa/site-banner.jpg';
        $urlB = 'https://cdn2.sportngin.com/attachments/photo/bb/img.jpg';
        $pageA = new PuckOutput('a', [['type' => 'Hero', 'props' => ['heading' => 'A', 'background_image' => $urlA]]], ['title' => 'A']);
        $pageB = new PuckOutput('b', [['type' => 'Hero', 'props' => ['heading' => 'B', 'background_image' => $urlB]]], ['title' => 'B']);
        $assembly = new AssemblyResult(
            pages: new DataCollection(PuckOutput::class, [$pageA, $pageB]),
            failures: new DataCollection(AssemblyFailure::class, []),
            block_issues_by_slug: [],
            status: AssemblyStatus::Complete,
            style_brief: new GlobalStyleBrief(
                brand_voice: '',
                palette: [],
                layout_conventions: [],
                nav: new DataCollection(NavItem::class, []),
            ),
        );
        $out = $this->resolver()->run($assembly, ['a' => "![]($urlA)", 'b' => "![]($urlB)"]);
        $this->assertCount(1, $out->scrub_issues_by_slug['a']);
        $this->assertCount(1, $out->scrub_issues_by_slug['b']);
    }

    #[Test]
    public function page_without_hero_block_is_untouched(): void
    {
        $page = new PuckOutput(
            page_slug: 'home',
            content: [['type' => 'Text', 'props' => ['body' => 'just text']]],
            root: ['title' => 'Home'],
        );
        $out = $this->resolver()->run($this->assemblyWith($page), ['home' => '![](https://cdn/x.jpg)']);
        $this->assertSame($page->content, $out->pages->items()[0]->content);
        $this->assertSame([], $out->scrub_issues_by_slug);
    }

    #[Test]
    public function hero_without_background_image_falls_back_to_first_source_image(): void
    {
        $first = 'https://cdn1.sportngin.com/attachments/photo/aa/img.jpg';
        $page = new PuckOutput(
            page_slug: 'home',
            content: [
                ['type' => 'Hero', 'props' => ['heading' => 'W']], // no background_image
            ],
            root: ['title' => 'Home'],
        );
        $md = "![]($first)\n";
        $out = $this->resolver()->run($this->assemblyWith($page), ['home' => $md]);
        $updated = $out->pages->items()[0];
        $this->assertSame($first, $updated->content[0]['props']['background_image']);
        $this->assertStringContainsString('first source-markdown image', $out->scrub_issues_by_slug['home'][0]->reason);
    }

    #[Test]
    public function hero_with_no_candidates_records_visible_no_op(): void
    {
        // Hero with no background_image AND no source images —
        // resolver has nothing to pick. Must still record the
        // decision so a reviewer can see the page had a Hero with
        // no image to place.
        $page = new PuckOutput(
            page_slug: 'home',
            content: [['type' => 'Hero', 'props' => ['heading' => 'W']]],
            root: ['title' => 'Home'],
        );
        $out = $this->resolver()->run($this->assemblyWith($page), ['home' => '']);
        $issues = $out->scrub_issues_by_slug['home'] ?? [];
        $this->assertCount(1, $issues);
        $this->assertSame(ScrubKind::HeroImageChosen, $issues[0]->kind);
        $this->assertStringContainsString('no candidate hero image', $issues[0]->reason);
    }
}
