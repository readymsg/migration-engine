<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

use App\Data\SiteImport\Block;
use App\Data\SiteImport\Diagnostic;
use Spatie\LaravelData\Optional;

// Translates our OLD-schema PuckOutput content (from block-fill +
// assembler) into contract-schema Block[]. This is the load-bearing
// bridge from what block-fill produces to what TeamLinkt ingests.
//
// M1 THIN-SLICE PALETTE:
//   Text, Hero, Image, Gallery, Button. Anything the old schema
//   emits that doesn't fit those five is either rewritten or
//   dropped-with-diagnostic. The full palette (StatsCounter,
//   Testimonials, FAQ, Accordion, FeatureGrid, Locations, Video,
//   Table, Tabs, Slider, Grid, TwoColumn, Section, ContactForm,
//   Spacer, TeamMembers + platform widgets) is Slice 14/15 work
//   after M1 lands.
//
// MAPPING TABLE (OLD → CONTRACT):
//
//   Text{body, align?}          → Text{body: sanitised, as: "p", align}
//   Heading{level, text}        → Text{body: text (plain), as: h2|h3}
//                                   h1 downgraded to h2 (page owns h1);
//                                   h4-h6 collapsed to h3 (contract cap)
//   Hero{background_image,      → Hero{imageUrl: tl-asset:<ref>,
//        heading, subheading?}       heading, subheading}
//   Image{src, alt?, caption?}  → Image{src: tl-asset:<ref>, alt, caption}
//   Gallery{items[], title?}    → Gallery{images[]: {src: tl-asset:<ref>,
//                                   alt, caption}, heading: title}
//   ButtonGroup{buttons[]}      → sequence of Button{label, href, variant}
//   Card{title, body,           → sequence of Text(h3, title) +
//        image?, href?}              Text(body html) +
//                                   Image(image, alt=title) +
//                                   Button(label='Learn more', href)
//                                   emitted only when card actually
//                                   carries the field
//   Columns{columns: [{         → children flattened into top level;
//     children[]}]}                 diagnostic emitted (Grid deferred)
//
// UNRESOLVED ASSET URLS: when a src/background_image can't be
// resolved back to a source_url (empty, /preview-assets/*, malformed)
// we emit a diagnostic AND drop the block that depended on it. A
// Hero without a background is still emitted (contract's Hero allows
// no imageUrl — falls to template default); a Gallery item without a
// resolvable src is skipped from the images[] array.
//
// BLOCK IDS: `{lowercase-type}-{6 base36 chars of content hash}`.
// Deterministic — same block content, same id — so fixture replays
// are reproducible.
final class PuckToContractMapper
{
    public function __construct(
        private readonly RichTextSanitizer $sanitiser,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $content  raw content array from an old PuckOutput
     */
    public function mapContent(
        array $content,
        AssetContext $assetContext,
        AssetLedger $ledger,
        ?string $sourcePageUrl = null,
    ): MappedContent {
        $blocks = [];
        $diagnostics = [];

        foreach ($content as $i => $entry) {
            if (! is_array($entry) || ! is_string($entry['type'] ?? null)) {
                continue;
            }
            $result = $this->mapOne($entry, $assetContext, $ledger, $sourcePageUrl);
            foreach ($result->blocks as $b) {
                $blocks[] = $b;
            }
            foreach ($result->diagnostics as $d) {
                $diagnostics[] = $d;
            }
        }

        return new MappedContent(blocks: $blocks, diagnostics: $diagnostics);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function mapOne(
        array $entry,
        AssetContext $assetContext,
        AssetLedger $ledger,
        ?string $sourcePageUrl,
    ): MappedContent {
        $type = (string) $entry['type'];
        $props = is_array($entry['props'] ?? null) ? $entry['props'] : [];

        return match ($type) {
            'Text' => $this->mapText($props),
            'Heading' => $this->mapHeading($props),
            'Hero' => $this->mapHero($props, $assetContext, $ledger, $sourcePageUrl),
            'Image' => $this->mapImage($props, $assetContext, $ledger, $sourcePageUrl),
            'Gallery' => $this->mapGallery($props, $assetContext, $ledger, $sourcePageUrl),
            'ButtonGroup' => $this->mapButtonGroup($props),
            'Card' => $this->mapCard($props, $assetContext, $ledger, $sourcePageUrl),
            'Columns' => $this->mapColumns($props, $assetContext, $ledger, $sourcePageUrl),
            default => new MappedContent(
                blocks: [],
                diagnostics: [new Diagnostic(
                    severity: 'warning',
                    code: 'unmappable_block_type',
                    message: "Old-schema block `{$type}` has no contract equivalent in the M1 palette. Dropped.",
                    sourceUrl: $sourcePageUrl !== null ? $sourcePageUrl : new Optional,
                )],
            ),
        };
    }

    /** @param array<string, mixed> $props */
    private function mapText(array $props): MappedContent
    {
        $body = is_string($props['body'] ?? null) ? $props['body'] : '';
        $body = $this->sanitiser->sanitize($body);
        if ($body === '') {
            return new MappedContent(blocks: [], diagnostics: []);
        }
        $align = is_string($props['align'] ?? null) && in_array($props['align'], ['left', 'center', 'right'], true)
            ? $props['align']
            : 'left';

        $out = [
            'id' => $this->id('text', $body),
            'body' => $body,
            'as' => 'p',
        ];
        if ($align !== 'left') {
            $out['align'] = $align;
        }

        return new MappedContent(blocks: [new Block(type: 'Text', props: $out)], diagnostics: []);
    }

    /** @param array<string, mixed> $props */
    private function mapHeading(array $props): MappedContent
    {
        $text = is_string($props['text'] ?? null) ? trim($props['text']) : '';
        if ($text === '') {
            return new MappedContent(blocks: [], diagnostics: []);
        }
        $text = $this->sanitiser->plainText($text);
        $level = $props['level'] ?? 2;
        $level = is_int($level) ? $level : (int) $level;
        // Page owns h1; contract Text.as caps at h3.
        $as = match (true) {
            $level <= 1 => 'h2',
            $level === 2 => 'h2',
            default => 'h3',
        };

        return new MappedContent(
            blocks: [new Block(type: 'Text', props: [
                'id' => $this->id('text', "{$as}:{$text}"),
                'body' => $text,
                'as' => $as,
            ])],
            diagnostics: [],
        );
    }

    /** @param array<string, mixed> $props */
    private function mapHero(array $props, AssetContext $ctx, AssetLedger $ledger, ?string $srcUrl): MappedContent
    {
        $heading = $this->sanitiser->plainText(is_string($props['heading'] ?? null) ? $props['heading'] : '');
        $subheading = $this->sanitiser->plainText(is_string($props['subheading'] ?? null) ? $props['subheading'] : '');
        $preheading = $this->sanitiser->plainText(is_string($props['preheading'] ?? null) ? $props['preheading'] : '');

        $out = ['id' => $this->id('hero', $heading.$subheading)];
        if ($heading !== '') {
            $out['heading'] = $heading;
        }
        if ($subheading !== '') {
            $out['subheading'] = $subheading;
        }
        if ($preheading !== '') {
            $out['preheading'] = $preheading;
        }

        $bg = is_string($props['background_image'] ?? null) ? $props['background_image'] : '';
        $diagnostics = [];
        if ($bg !== '') {
            $token = $this->tokenise($bg, $ctx, $ledger, 'hero', $heading !== '' ? $heading : null);
            if ($token !== null) {
                $out['imageUrl'] = $token;
            } else {
                $diagnostics[] = new Diagnostic(
                    severity: 'warning',
                    code: 'hero_image_unresolvable',
                    message: "Hero background_image `{$bg}` couldn't be resolved to a source URL. Emitting Hero without imageUrl (template default fills).",
                    sourceUrl: $srcUrl !== null ? $srcUrl : new Optional,
                );
            }
        }

        return new MappedContent(blocks: [new Block(type: 'Hero', props: $out)], diagnostics: $diagnostics);
    }

    /** @param array<string, mixed> $props */
    private function mapImage(array $props, AssetContext $ctx, AssetLedger $ledger, ?string $srcUrl): MappedContent
    {
        $src = is_string($props['src'] ?? null) ? $props['src'] : '';
        if ($src === '') {
            return new MappedContent(
                blocks: [],
                diagnostics: [new Diagnostic(
                    severity: 'warning',
                    code: 'image_missing_src',
                    message: 'Image block dropped: no src.',
                    sourceUrl: $srcUrl !== null ? $srcUrl : new Optional,
                )],
            );
        }
        $alt = $this->sanitiser->plainText(is_string($props['alt'] ?? null) ? $props['alt'] : '');
        $caption = $this->sanitiser->plainText(is_string($props['caption'] ?? null) ? $props['caption'] : '');
        $token = $this->tokenise($src, $ctx, $ledger, 'image', $alt !== '' ? $alt : null);
        if ($token === null) {
            return new MappedContent(
                blocks: [],
                diagnostics: [new Diagnostic(
                    severity: 'warning',
                    code: 'image_asset_unresolvable',
                    message: "Image src `{$src}` couldn't be resolved to a source URL. Block dropped.",
                    sourceUrl: $srcUrl !== null ? $srcUrl : new Optional,
                )],
            );
        }

        $out = [
            'id' => $this->id('image', $src),
            'src' => $token,
        ];
        if ($alt !== '') {
            $out['alt'] = $alt;
        }
        if ($caption !== '') {
            $out['caption'] = $caption;
        }

        return new MappedContent(blocks: [new Block(type: 'Image', props: $out)], diagnostics: []);
    }

    /** @param array<string, mixed> $props */
    private function mapGallery(array $props, AssetContext $ctx, AssetLedger $ledger, ?string $srcUrl): MappedContent
    {
        $items = is_array($props['items'] ?? null) ? $props['items'] : [];
        $images = [];
        $diagnostics = [];
        foreach ($items as $i => $item) {
            if (! is_array($item)) {
                continue;
            }
            $src = is_string($item['src'] ?? null) ? $item['src'] : '';
            if ($src === '') {
                continue;
            }
            $alt = $this->sanitiser->plainText(is_string($item['alt'] ?? null) ? $item['alt'] : '');
            $caption = $this->sanitiser->plainText(is_string($item['caption'] ?? null) ? $item['caption'] : '');
            $token = $this->tokenise($src, $ctx, $ledger, 'gallery', $alt !== '' ? $alt : null);
            if ($token === null) {
                $diagnostics[] = new Diagnostic(
                    severity: 'info',
                    code: 'gallery_item_dropped',
                    message: "Gallery item {$i} src `{$src}` unresolvable; dropped from images[].",
                    sourceUrl: $srcUrl !== null ? $srcUrl : new Optional,
                );

                continue;
            }
            $imageEntry = ['src' => $token, 'alt' => $alt, 'caption' => $caption];
            $images[] = $imageEntry;
        }
        if ($images === []) {
            return new MappedContent(
                blocks: [],
                diagnostics: array_merge($diagnostics, [new Diagnostic(
                    severity: 'warning',
                    code: 'gallery_all_items_unresolvable',
                    message: 'Gallery block dropped: every images[] entry was unresolvable.',
                    sourceUrl: $srcUrl !== null ? $srcUrl : new Optional,
                )]),
            );
        }

        $heading = $this->sanitiser->plainText(is_string($props['title'] ?? null) ? $props['title'] : '');
        $out = [
            'id' => $this->id('gallery', $srcUrl ?? '', (string) count($images)),
            'images' => $images,
        ];
        if ($heading !== '') {
            $out['heading'] = $heading;
        }

        return new MappedContent(
            blocks: [new Block(type: 'Gallery', props: $out)],
            diagnostics: $diagnostics,
        );
    }

    /** @param array<string, mixed> $props */
    private function mapButtonGroup(array $props): MappedContent
    {
        $buttons = is_array($props['buttons'] ?? null) ? $props['buttons'] : [];
        $blocks = [];
        foreach ($buttons as $i => $b) {
            if (! is_array($b)) {
                continue;
            }
            $label = $this->sanitiser->plainText(is_string($b['label'] ?? null) ? $b['label'] : '');
            $href = is_string($b['href'] ?? null) ? trim($b['href']) : '';
            if ($label === '' || $href === '') {
                continue;
            }
            $variant = is_string($b['variant'] ?? null) && in_array($b['variant'], ['solid', 'soft', 'outline', 'ghost'], true)
                ? $b['variant']
                : 'solid';
            $blocks[] = new Block(type: 'Button', props: [
                'id' => $this->id('button', "{$label}:{$href}:{$i}"),
                'label' => $label,
                'href' => $href,
                'variant' => $variant,
            ]);
        }

        return new MappedContent(blocks: $blocks, diagnostics: []);
    }

    /** @param array<string, mixed> $props */
    private function mapCard(array $props, AssetContext $ctx, AssetLedger $ledger, ?string $srcUrl): MappedContent
    {
        $title = $this->sanitiser->plainText(is_string($props['title'] ?? null) ? $props['title'] : '');
        $body = $this->sanitiser->sanitize(is_string($props['body'] ?? null) ? $props['body'] : '');
        $image = is_string($props['image'] ?? null) ? $props['image'] : '';
        $href = is_string($props['href'] ?? null) ? trim($props['href']) : '';

        $blocks = [];
        $diagnostics = [];

        if ($title !== '') {
            $blocks[] = new Block(type: 'Text', props: [
                'id' => $this->id('text', "cardtitle:{$title}"),
                'body' => $title,
                'as' => 'h3',
            ]);
        }
        if ($image !== '') {
            $token = $this->tokenise($image, $ctx, $ledger, 'gallery', $title !== '' ? $title : null);
            if ($token !== null) {
                $props = ['id' => $this->id('image', "card:{$image}"), 'src' => $token];
                if ($title !== '') {
                    $props['alt'] = $title;
                }
                $blocks[] = new Block(type: 'Image', props: $props);
            } else {
                $diagnostics[] = new Diagnostic(
                    severity: 'info',
                    code: 'card_image_unresolvable',
                    message: "Card image `{$image}` unresolvable; card image dropped (text + button preserved).",
                    sourceUrl: $srcUrl !== null ? $srcUrl : new Optional,
                );
            }
        }
        if ($body !== '') {
            $blocks[] = new Block(type: 'Text', props: [
                'id' => $this->id('text', "cardbody:{$body}"),
                'body' => $body,
                'as' => 'p',
            ]);
        }
        if ($href !== '' && (str_starts_with($href, 'http') || str_starts_with($href, '/') || str_starts_with($href, '#'))) {
            $blocks[] = new Block(type: 'Button', props: [
                'id' => $this->id('button', "cardhref:{$href}"),
                'label' => 'Learn more',
                'href' => $href,
                'variant' => 'outline',
            ]);
        }

        return new MappedContent(blocks: $blocks, diagnostics: $diagnostics);
    }

    /** @param array<string, mixed> $props */
    private function mapColumns(array $props, AssetContext $ctx, AssetLedger $ledger, ?string $srcUrl): MappedContent
    {
        // Contract Grid is out of the M1 palette. Flatten: extract
        // every column's children and emit them at the top level,
        // in order. Record the flattening as a diagnostic so a
        // reviewer can see the layout signal was lost.
        $columns = is_array($props['columns'] ?? null) ? $props['columns'] : [];
        $flatContent = [];
        foreach ($columns as $col) {
            if (! is_array($col)) {
                continue;
            }
            $children = is_array($col['children'] ?? null) ? $col['children'] : [];
            foreach ($children as $child) {
                if (is_array($child)) {
                    $flatContent[] = $child;
                }
            }
        }
        if ($flatContent === []) {
            return new MappedContent(blocks: [], diagnostics: []);
        }
        $mapped = $this->mapContent($flatContent, $ctx, $ledger, $srcUrl);
        $diag = new Diagnostic(
            severity: 'info',
            code: 'columns_flattened',
            message: 'Columns layout flattened to single-column stack (Grid block deferred beyond M1 palette).',
            sourceUrl: $srcUrl !== null ? $srcUrl : new Optional,
        );

        return new MappedContent(
            blocks: $mapped->blocks,
            diagnostics: array_merge([$diag], $mapped->diagnostics),
        );
    }

    /**
     * Convert a URL-shaped prop value (s3:// or http(s)) into a
     * `tl-asset:<ref>` token by way of the AssetLedger. Returns null
     * when the URL can't be resolved to a source_url OR when the
     * resolved asset is rejected by the ledger (SVG, non-whitelisted
     * mime, etc). Caller emits a diagnostic on null.
     */
    private function tokenise(
        string $url,
        AssetContext $ctx,
        AssetLedger $ledger,
        string $usage,
        ?string $alt,
    ): ?string {
        $resolved = $ctx->resolve($url);
        if ($resolved === null) {
            return null;
        }

        return $ledger->tokenFor(
            sourceUrl: $resolved['sourceUrl'],
            filename: $resolved['filename'],
            mimeType: $resolved['mimeType'],
            alt: $alt,
            usage: $usage,
        );
    }

    /**
     * Deterministic block id — same content, same id (fixture reproducibility).
     * `{prefix}-{6 base36 chars}`. Satisfies contract's uniqueness rule
     * when paired with per-page keying and any collisions get a numeric
     * suffix; the envelope-level validator will catch the rare collision.
     */
    private function id(string $prefix, string ...$parts): string
    {
        $hash = substr(sha1(implode('|', $parts)), 0, 6);

        return "{$prefix}-{$hash}";
    }
}
