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
     * @param  bool  $isTopLevel  true for the outer mapContent call — used to gate page-level pre-scans (Locations widget). Recursive calls from mapColumns pass false so nested-in-Grid content doesn't produce a Locations widget inside each Grid slot.
     * @param  MapperAudit|null  $audit  optional transformation ledger — records (cause, inputBlocks, outputBlocks) per mapper call for the BlockDeltaAuditor to reconcile against.
     */
    public function mapContent(
        array $content,
        AssetContext $assetContext,
        AssetLedger $ledger,
        ?string $sourcePageUrl = null,
        bool $isTopLevel = true,
        ?MapperAudit $audit = null,
    ): MappedContent {
        $blocks = [];
        $diagnostics = [];

        // Slice 15d: at page top level, scan the WHOLE tree for
        // Google Maps static-map URLs. If any are found, emit ONE
        // Locations widget at the top of the page and skip all
        // Google Maps Images downstream (both top-level and nested
        // in Columns children).
        if ($isTopLevel) {
            $mapUrlsCount = $this->countGoogleMapsUrlsRecursively($content);
            if ($mapUrlsCount > 0) {
                $blocks[] = new Block(type: 'Locations', props: [
                    'id' => $this->id('locations', $sourcePageUrl ?? '', (string) $mapUrlsCount),
                ]);
                $diagnostics[] = new Diagnostic(
                    severity: 'info',
                    code: 'locations_widget_placed',
                    message: sprintf(
                        'Detected %d Google Maps static-map image(s) on this page. Placed a Locations widget; scraped map URLs discarded (widgets read live from TeamLinkt).',
                        $mapUrlsCount,
                    ),
                    sourceUrl: $sourcePageUrl !== null ? $sourcePageUrl : new Optional,
                );
                // Audit: N Google Maps images consumed, 1 Locations widget emitted.
                $audit?->record('google_maps_to_locations', $mapUrlsCount, 1);
            }
        }

        foreach ($content as $i => $entry) {
            if (! is_array($entry) || ! is_string($entry['type'] ?? null)) {
                continue;
            }
            // Skip Google Maps Images — consumed by the page-level
            // Locations widget (already emitted or emitted at an
            // ancestor mapContent call). Do NOT audit — they were
            // already accounted for in the locations_widget_placed
            // record above.
            if ($this->isGoogleMapsImage($entry)) {
                continue;
            }
            $result = $this->mapOne($entry, $assetContext, $ledger, $sourcePageUrl, $audit);
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
     * @param  array<int, array<string, mixed>>  $content
     */
    private function countGoogleMapsUrlsRecursively(array $content): int
    {
        $count = 0;
        foreach ($content as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if ($this->isGoogleMapsImage($entry)) {
                $count++;

                continue;
            }
            // Recurse into Columns children.
            if (($entry['type'] ?? null) === 'Columns') {
                $columns = is_array($entry['props']['columns'] ?? null) ? $entry['props']['columns'] : [];
                foreach ($columns as $col) {
                    if (! is_array($col)) {
                        continue;
                    }
                    $children = is_array($col['children'] ?? null) ? $col['children'] : [];
                    $count += $this->countGoogleMapsUrlsRecursively($children);
                }
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function isGoogleMapsImage(array $entry): bool
    {
        if (($entry['type'] ?? null) !== 'Image') {
            return false;
        }
        $props = is_array($entry['props'] ?? null) ? $entry['props'] : [];
        $src = is_string($props['src'] ?? null) ? $props['src'] : '';

        return str_contains($src, 'maps.googleapis.com')
            || str_contains($src, 'maps.google.com');
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function mapOne(
        array $entry,
        AssetContext $assetContext,
        AssetLedger $ledger,
        ?string $sourcePageUrl,
        ?MapperAudit $audit,
    ): MappedContent {
        $type = (string) $entry['type'];
        $props = is_array($entry['props'] ?? null) ? $entry['props'] : [];

        $result = match ($type) {
            'Text' => $this->mapText($props, $sourcePageUrl),
            'Heading' => $this->mapHeading($props),
            'Hero' => $this->mapHero($props, $assetContext, $ledger, $sourcePageUrl),
            'Image' => $this->mapImage($props, $assetContext, $ledger, $sourcePageUrl),
            'Gallery' => $this->mapGallery($props, $assetContext, $ledger, $sourcePageUrl),
            'ButtonGroup' => $this->mapButtonGroup($props),
            'Card' => $this->mapCard($props, $assetContext, $ledger, $sourcePageUrl),
            'Columns' => $this->mapColumns($props, $assetContext, $ledger, $sourcePageUrl, $audit),
            'PlatformTeams' => $this->mapPlatformTeams(),
            'PlatformTeam' => $this->mapPlatformTeam(),
            'PlatformDivisions' => $this->mapPlatformDivisions(),
            'PlatformNews' => $this->mapPlatformNews(),
            'PlatformContacts' => $this->mapPlatformContacts(),
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

        // Audit reporting per-block-type. Columns is special: it
        // handles its own audit records (fold-to-widget or Grid
        // recursion) inside mapColumns, so skip it here to avoid
        // double-counting.
        if ($type !== 'Columns') {
            $inputBlocks = $this->inputBlockCountFor($type, $props);
            $audit?->record("map_{$this->snake($type)}", $inputBlocks, count($result->blocks));
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function inputBlockCountFor(string $type, array $props): int
    {
        // ButtonGroup is 1-source-block-with-N-buttons in old
        // schema. Each button represents a source "block" for
        // audit purposes because mapButtonGroup emits N Buttons.
        if ($type === 'ButtonGroup') {
            $buttons = is_array($props['buttons'] ?? null) ? $props['buttons'] : [];

            return count($buttons);
        }

        return 1;
    }

    private function snake(string $s): string
    {
        return strtolower((string) preg_replace('/([A-Z])/', '_$1', lcfirst($s)));
    }

    /** @param array<string, mixed> $props */
    private function mapText(array $props, ?string $sourcePageUrl = null): MappedContent
    {
        $rawBody = is_string($props['body'] ?? null) ? $props['body'] : '';

        // IR concept `video`: a Text block whose body's primary content
        // is a YouTube/Vimeo URL, either as a markdown link or bare.
        // Checked BEFORE file_download because a single link line
        // could match either — but the video URL host discriminates.
        $videoFold = $this->tryFoldToVideo($rawBody);
        if ($videoFold !== null) {
            return new MappedContent(
                blocks: [$videoFold],
                diagnostics: [new Diagnostic(
                    severity: 'info',
                    code: 'text_body_folded_to_video',
                    message: 'Detected YouTube/Vimeo URL in a compact text body. Folded to a Video block.',
                )],
            );
        }

        // IR concept `file_download`: a Text block whose body is
        // exactly ONE heading-styled or bulleted document-link line
        // (URL ends in .pdf/.doc/.docx/.xls/.xlsx/.ppt/.pptx). Fold
        // to a FileDownload block. Checked BEFORE FeatureGrid because
        // a single doc-link heading isn't grid-shaped. Multiple
        // doc-link headings (langdon For Coaches with 9) still fold
        // to FeatureGrid via the ≥3-item gate below.
        $fileFold = $this->tryFoldToFileDownload($rawBody);
        if ($fileFold !== null) {
            return new MappedContent(
                blocks: [$fileFold],
                diagnostics: [new Diagnostic(
                    severity: 'info',
                    code: 'text_body_folded_to_file_download',
                    message: 'Detected single heading-styled document link. Folded to a FileDownload block.',
                )],
            );
        }

        // IR concept `feature_grid`: block-fill emitted a Text block
        // whose body is exclusively link-only lines (bulleted `- [T](u)`,
        // heading `### [T](u)`, or bare `[T](u)`), ≥3 items. cjfl Awards
        // and langdon For Coaches are the canonical shapes. Checked
        // BEFORE FAQ because a link-only body has no `**Q?**` markers,
        // and BEFORE stat_table because link-only items have no
        // column separators. Does NOT compete with the existing
        // Sponsors / TeamMembers / NewsList / Grid folds — those live
        // inside mapColumns on Card sequences, a different source
        // shape entirely.
        $featureGridFold = $this->tryFoldToFeatureGrid($rawBody);
        if ($featureGridFold !== null) {
            return new MappedContent(
                blocks: [$featureGridFold],
                diagnostics: [new Diagnostic(
                    severity: 'info',
                    code: 'text_body_folded_to_feature_grid',
                    message: 'Detected link-heading grid pattern (≥3 link-only lines with no interstitial prose). Folded to a FeatureGrid block.',
                )],
            );
        }

        // IR concept `qa_section`: block-fill emitted a Text block
        // whose body contains ≥3 `**Question?**` bold-question markers,
        // or is preceded by a "Frequently Asked Questions" heading
        // with ≥2 questions. Mapper picks target block from PAGE
        // CONTEXT — contract guidance is:
        //   "FAQ for a dedicated FAQ page; Accordion for expandable
        //    sections inside another page."
        // Detection is one, target is contextual:
        //   - Source URL slug indicates FAQ  → FAQ block
        //   - Otherwise (section within a broader page) → Accordion
        // Both blocks' items[].body are richtext props (per
        // x-teamlinkt.vocabularies.richtext.props), so the sanitiser
        // applies uniformly across the branch.
        // Checked BEFORE stat_table because Q&A answers can contain
        // bullet lists (see "What Division would my child be based on
        // Age?" on langdon For Parents).
        $qaFold = $this->tryFoldToQaSection($rawBody, $sourcePageUrl);
        if ($qaFold !== null) {
            return new MappedContent(
                blocks: [$qaFold->block],
                diagnostics: [new Diagnostic(
                    severity: 'info',
                    code: $qaFold->block->type === 'FAQ'
                        ? 'text_body_folded_to_faq'
                        : 'text_body_folded_to_accordion',
                    message: $qaFold->block->type === 'FAQ'
                        ? 'Detected Q&A pattern on a dedicated FAQ page (slug indicates FAQ). Folded to an FAQ block.'
                        : 'Detected Q&A pattern within a broader page (slug does not indicate FAQ). Folded to an Accordion block.',
                )],
            );
        }

        // IR concept `stat_table`: block-fill emitted a Text block whose
        // body is a markdown list of consistent-shape record rows (year —
        // name — team — position on cjfl award pages). Fold to a Table
        // block so record content renders tabular instead of collapsing
        // to a wall of prose. Detection is deliberately strict — see
        // tryFoldToTable's docblock for the exact gate; adjacent
        // patterns (FeatureGrid link-headings, FAQ Q/A, short bullet
        // lists) don't match.
        $tableFold = $this->tryFoldToTable($rawBody);
        if ($tableFold !== null) {
            return new MappedContent(
                blocks: [$tableFold],
                diagnostics: [new Diagnostic(
                    severity: 'info',
                    code: 'text_list_folded_to_table',
                    message: 'Detected record-list pattern (bullet list, ≥5 items, consistent column separator). Folded to a Table block.',
                )],
            );
        }

        // No fold matched — check for near-miss patterns before falling
        // through to plain Text. Slice A closes the silent-loss surface
        // where a body ALMOST matches a fold gate: a code-with-example
        // in the envelope's diagnostics list turns "invisible near-miss"
        // into a reviewable signal and a tuning-feedback loop for
        // future sites. Specific codes per cause so Metabase can
        // histogram them independently (different causes imply
        // different tuning actions).
        $nearMissDiagnostics = $this->collectNearMissDiagnostics($rawBody, $sourcePageUrl);

        $body = $this->sanitiser->sanitize($rawBody);
        if ($body === '') {
            // Silent-loss guard: source had a Text block. If pre-
            // sanitize was empty, the source itself was no-op. If
            // pre-sanitize was NON-empty (had markup) but sanitize
            // reduced it to '', the TipTap-subset stripper devoured
            // the whole thing — reviewer should see.
            $diagnostics = $nearMissDiagnostics;
            if (trim($rawBody) !== '') {
                $diagnostics[] = new Diagnostic(
                    severity: 'warning',
                    code: 'text_body_sanitised_to_empty',
                    message: 'Text block dropped: body was non-empty in source but sanitised to nothing (all markup outside the TipTap subset).',
                );
            }

            return new MappedContent(blocks: [], diagnostics: $diagnostics);
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

        return new MappedContent(
            blocks: [new Block(type: 'Text', props: $out)],
            diagnostics: $nearMissDiagnostics,
        );
    }

    /** @param array<string, mixed> $props */
    private function mapHeading(array $props): MappedContent
    {
        $text = is_string($props['text'] ?? null) ? trim($props['text']) : '';
        if ($text === '') {
            return new MappedContent(
                blocks: [],
                diagnostics: [new Diagnostic(
                    severity: 'info',
                    code: 'heading_dropped_empty',
                    message: 'Heading block dropped: source `text` field was empty.',
                )],
            );
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
        $droppedForMissingLabel = 0;
        $droppedForMissingHref = 0;
        foreach ($buttons as $i => $b) {
            if (! is_array($b)) {
                continue;
            }
            $label = $this->sanitiser->plainText(is_string($b['label'] ?? null) ? $b['label'] : '');
            $href = is_string($b['href'] ?? null) ? trim($b['href']) : '';
            if ($label === '') {
                $droppedForMissingLabel++;

                continue;
            }
            if ($href === '') {
                $droppedForMissingHref++;

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

        $diagnostics = [];
        // Silent-loss guards. ButtonGroup with mixed valid+invalid
        // buttons: the invalids drop; log the count so a reviewer
        // knows the group wasn't emitted whole. Empty group (all
        // buttons rejected OR source had zero buttons): flag as its
        // own drop with the specific reason.
        $totalIn = count($buttons);
        if ($blocks === []) {
            $diagnostics[] = new Diagnostic(
                severity: 'warning',
                code: 'button_group_dropped_empty',
                message: sprintf(
                    'ButtonGroup dropped: source had %d button(s), 0 survived (missing label=%d, missing href=%d).',
                    $totalIn,
                    $droppedForMissingLabel,
                    $droppedForMissingHref,
                ),
            );
        } elseif ($droppedForMissingLabel > 0 || $droppedForMissingHref > 0) {
            $diagnostics[] = new Diagnostic(
                severity: 'info',
                code: 'button_group_partial',
                message: sprintf(
                    'ButtonGroup partial: %d of %d buttons emitted (%d dropped for missing label, %d for missing href).',
                    count($blocks),
                    $totalIn,
                    $droppedForMissingLabel,
                    $droppedForMissingHref,
                ),
            );
        }

        return new MappedContent(blocks: $blocks, diagnostics: $diagnostics);
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

        // Silent-loss guard: a Card that had SOMETHING in source but
        // produced zero output blocks (all fields sanitised away, or
        // image failed to resolve AND no other content). Log it so
        // the reviewer knows a Card was in source but didn't survive.
        if ($blocks === []) {
            $hadAnyField = $title !== '' || $body !== '' || $image !== '' || $href !== '';
            if ($hadAnyField) {
                $diagnostics[] = new Diagnostic(
                    severity: 'info',
                    code: 'card_dropped_no_survivable_content',
                    message: 'Card dropped: source had fields but none survived sanitising / asset-resolving.',
                );
            }
        }

        return new MappedContent(blocks: $blocks, diagnostics: $diagnostics);
    }

    /** @param array<string, mixed> $props */
    private function mapColumns(
        array $props,
        AssetContext $ctx,
        AssetLedger $ledger,
        ?string $srcUrl,
        ?MapperAudit $audit,
    ): MappedContent {
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
        $flatContentCount = $this->countChildrenAsSourceBlocks($flatContent);
        if ($flatContent === []) {
            return new MappedContent(blocks: [], diagnostics: []);
        }

        // Slice 13: people-directory detection.
        if ($this->looksLikePeopleDirectory($flatContent)) {
            $columnCount = count($columns);
            $audit?->record('columns_fold_teammembers', $flatContentCount, 1);

            return $this->emitTeamMembers($flatContent, $columnCount, $ctx, $ledger, $srcUrl);
        }

        // Slice 15b: sponsor-deck detection.
        if ($this->looksLikeSponsorDeck($flatContent)) {
            $audit?->record('columns_fold_sponsors', $flatContentCount, 1);

            return $this->emitSponsors($flatContent, $srcUrl);
        }

        // Slice 15e: news-list detection.
        if ($this->looksLikeNewsList($flatContent)) {
            $audit?->record('columns_fold_newslist', $flatContentCount, 1);

            return $this->emitNewsList($flatContent, $srcUrl);
        }

        // Slice 15c: emit a Grid block. mapContent recurse call
        // handles per-child auditing; we record the +1 Grid wrapper
        // separately.
        $result = $this->emitGrid($columns, $ctx, $ledger, $srcUrl, $audit);
        $audit?->record('columns_wrap_grid', 0, 1);

        return $result;
    }

    // ── Platform block placeholders (Finding 2 fix) ──────────────────
    //
    // PlatformBlockRenderer emits {type: 'Platform<X>', props: {org_id}}
    // shells from PlatformDynamic ledger entries. These are engine-
    // emitted placeholders — the product's runtime React component
    // fills real data (rosters, news, divisions, contacts) at render
    // time from the org's own database via `org_id`.
    //
    // The mapper turns each shell into a SPARSE contract block:
    // just `id`, omit `selection` / `resolvedItems` / `items` etc.
    // Hard rule #1 ("send sparse props; omitting takes the default")
    // + hard rule #6 ("never emit a prop beginning with `resolved`,
    // nor formUuid") lock this shape.
    //
    // Mapping table (from x-teamlinkt.orgTypeGating + inspection of
    // the 45-block schema):
    //
    //   PlatformTeams (Teams directory)   → Teams               (league-gated)
    //   PlatformTeam (single team page)   → TeamRoster          (league-gated) [see docblock]
    //   PlatformDivisions (conferences)   → SubOrganizations    (all orgTypes)
    //   PlatformNews (news feed)          → NewsList            (all orgTypes)
    //   PlatformContacts (contact list)   → TeamMembers         (all orgTypes)
    //
    // OrgTypeGate (post-mapping in ContractPayloadEmitter) gates the
    // league-restricted targets. Under orgType=club, Teams and
    // TeamRoster get dropped with `org_type_gate_dropped` — CORRECT
    // visible behavior (was silent `unmappable_block_type` before).
    //
    // Contract-doc gap flag: BUILD.md's v1 scope cut says "we NOT
    // extract or provision TeamLinkt data — no teams, divisions,
    // admins, or team logos", and the contract prose document
    // (site-import-contract.md, not in this repo) is documented to
    // have a "Pages you should not create" section. If that section
    // forbids per-team pages via import, the PlatformTeam → TeamRoster
    // mapping below should be revised to drop-with-diagnostic. Today
    // the mapping keeps the page tree consistent with what
    // PageTreeBuilder already produced — the page shell exists either
    // way; the emit-placeholder path avoids leaving 19 empty pages.

    private function mapPlatformTeams(): MappedContent
    {
        return new MappedContent(
            blocks: [new Block(type: 'Teams', props: [
                'id' => $this->id('teams', 'platform'),
            ])],
            diagnostics: [new Diagnostic(
                severity: 'info',
                code: 'platform_block_mapped_to_teams',
                message: 'PlatformTeams (Teams directory) → Teams block (sparse; runtime component fills data via org_id).',
            )],
        );
    }

    private function mapPlatformTeam(): MappedContent
    {
        return new MappedContent(
            blocks: [new Block(type: 'TeamRoster', props: [
                'id' => $this->id('team-roster', 'platform'),
            ])],
            diagnostics: [new Diagnostic(
                severity: 'info',
                code: 'platform_block_mapped_to_team_roster',
                message: 'PlatformTeam (single team page) → TeamRoster block (sparse; empty selection = placeholder; runtime component fills roster via org_id + team context).',
            )],
        );
    }

    private function mapPlatformDivisions(): MappedContent
    {
        return new MappedContent(
            blocks: [new Block(type: 'SubOrganizations', props: [
                'id' => $this->id('sub-organizations', 'platform'),
            ])],
            diagnostics: [new Diagnostic(
                severity: 'info',
                code: 'platform_block_mapped_to_sub_organizations',
                message: 'PlatformDivisions (league conferences / sub-orgs) → SubOrganizations block (sparse; runtime resolves org members).',
            )],
        );
    }

    private function mapPlatformNews(): MappedContent
    {
        return new MappedContent(
            blocks: [new Block(type: 'NewsList', props: [
                'id' => $this->id('news-list', 'platform'),
            ])],
            diagnostics: [new Diagnostic(
                severity: 'info',
                code: 'platform_block_mapped_to_news_list',
                message: 'PlatformNews (news feed) → NewsList block (sparse; resolvedItems is server-owned and never authored — runtime resolver fills).',
            )],
        );
    }

    private function mapPlatformContacts(): MappedContent
    {
        return new MappedContent(
            blocks: [new Block(type: 'TeamMembers', props: [
                'id' => $this->id('team-members', 'platform'),
            ])],
            diagnostics: [new Diagnostic(
                severity: 'info',
                code: 'platform_block_mapped_to_team_members',
                message: 'PlatformContacts (contact directory) → TeamMembers block (sparse; runtime component fills people cards from admin roster).',
            )],
        );
    }

    /**
     * Convert flattened column children to a source-block count for
     * audit purposes. ButtonGroup contributes N-buttons per group
     * (matches the ButtonGroup→N-Buttons rule in inputBlockCountFor).
     *
     * @param  array<int, array<string, mixed>>  $children
     */
    private function countChildrenAsSourceBlocks(array $children): int
    {
        $count = 0;
        foreach ($children as $c) {
            if (! is_array($c) || ! is_string($c['type'] ?? null)) {
                continue;
            }
            if ($c['type'] === 'ButtonGroup') {
                $buttons = is_array($c['props']['buttons'] ?? null) ? $c['props']['buttons'] : [];
                $count += count($buttons);

                continue;
            }
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sourceColumns
     */
    private function emitGrid(
        array $sourceColumns,
        AssetContext $ctx,
        AssetLedger $ledger,
        ?string $srcUrl,
        ?MapperAudit $audit,
    ): MappedContent {
        $sourceCount = count($sourceColumns);
        // Contract Grid.columns enum is ["2","3","4"] STRINGS. Clamp
        // and record how the source count mapped.
        $emitCount = max(2, min(4, $sourceCount));
        $props = [
            'id' => $this->id('grid', $srcUrl ?? '', (string) $sourceCount),
            'columns' => (string) $emitCount,
        ];
        $diagnostics = [];

        // Redistribute source columns into the 2/3/4 slot count. If
        // sourceCount fits (2, 3, or 4), 1-to-1. If sourceCount is 1
        // (single-column Columns wrapping content), put all content
        // in column1 + leave others empty. If sourceCount > 4, pack
        // the extras into column4 with a diagnostic.
        $slots = ['column1' => [], 'column2' => [], 'column3' => [], 'column4' => []];
        foreach ($sourceColumns as $i => $col) {
            if (! is_array($col)) {
                continue;
            }
            $children = is_array($col['children'] ?? null) ? $col['children'] : [];
            if ($children === []) {
                continue;
            }
            // Nested call: pass isTopLevel=false so we don't
            // emit a Locations widget inside each Grid slot.
            // Audit is forwarded so nested-in-Grid mappings still
            // report their transformations.
            $mapped = $this->mapContent($children, $ctx, $ledger, $srcUrl, isTopLevel: false, audit: $audit);
            foreach ($mapped->diagnostics as $d) {
                $diagnostics[] = $d;
            }
            $slotIndex = min($i, $emitCount - 1);
            $slotName = 'column'.($slotIndex + 1);
            foreach ($mapped->blocks as $b) {
                $slots[$slotName][] = $b;
            }
        }
        if ($sourceCount > 4) {
            $diagnostics[] = new Diagnostic(
                severity: 'info',
                code: 'grid_columns_clamped',
                message: sprintf(
                    'Source had %d columns; Grid.columns caps at 4. Extras merged into column4.',
                    $sourceCount,
                ),
                sourceUrl: $srcUrl !== null ? $srcUrl : new Optional,
            );
        }
        // Only include slots that have content. Empty slots default
        // to [] via the block's defaults, so omitting is sparse-props
        // correct.
        // Slot children stored as ARRAY form (block->toArray()) not
        // Block objects, so downstream walkers (asset-token
        // extractor, id-uniqueness recurse) can traverse them via
        // array_walk_recursive without instanceof-Block gymnastics.
        for ($k = 1; $k <= $emitCount; $k++) {
            $slotName = 'column'.$k;
            if ($slots[$slotName] !== []) {
                $props[$slotName] = array_map(
                    static fn (Block $b) => $b->toArray(),
                    $slots[$slotName],
                );
            }
        }

        return new MappedContent(
            blocks: [new Block(type: 'Grid', props: $props)],
            diagnostics: array_merge([
                new Diagnostic(
                    severity: 'info',
                    code: 'columns_mapped_to_grid',
                    message: sprintf('Columns block mapped to Grid (%d source cols → %d Grid columns).', $sourceCount, $emitCount),
                    sourceUrl: $srcUrl !== null ? $srcUrl : new Optional,
                ),
            ], $diagnostics),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $children
     */
    private function looksLikePeopleDirectory(array $children): bool
    {
        // Every child must be a Card AND at least 3 total. Threshold
        // was 2 originally; bumped to 3 after the About Us false-
        // positive: 2 Cards with contact-email bodies but describing
        // PROGRAMS, not people ("Boys 3rd–6th Grade Flight Teams —
        // contact Michael Lewis at ..."). A two-card layout is more
        // likely a program/feature pair than a directory; three+
        // is the point where directory-shape becomes the dominant
        // interpretation.
        if (count($children) < 3) {
            return false;
        }
        $cardsWithSignal = 0;
        $cardsWithHref = 0;
        foreach ($children as $child) {
            if (! is_array($child) || ($child['type'] ?? null) !== 'Card') {
                return false;
            }
            $props = is_array($child['props'] ?? null) ? $child['props'] : [];
            $hasImage = is_string($props['image'] ?? null) && $props['image'] !== '';
            $body = is_string($props['body'] ?? null) ? $props['body'] : '';
            $hasEmail = str_contains($body, '@');
            $hasMultiLineBody = str_contains($body, "\n");
            if ($hasImage || $hasEmail || $hasMultiLineBody) {
                $cardsWithSignal++;
            }
            // Sponsors / resource links carry an href even when it's
            // a "#" placeholder ("Become a sponsor → #"). People
            // cards on the board / contacts pages don't. Count any
            // non-empty href as the link-intent signal.
            if (is_string($props['href'] ?? null) && trim($props['href']) !== '') {
                $cardsWithHref++;
            }
        }
        $half = (int) ceil(count($children) / 2);

        // Positive signal: majority have image OR email OR multi-line body.
        if ($cardsWithSignal < $half) {
            return false;
        }

        // Negative signal: sponsor/resource-link decks have href on
        // most cards ("Dicks Sporting Goods → Visit Website"). People
        // directory cards rarely carry an outbound href. Reject when
        // href is dominant even if the image signal is high.
        if ($cardsWithHref >= $half) {
            return false;
        }

        return true;
    }

    /**
     * Sponsor deck pattern (Contract Part III "Blocks to place but
     * never populate — the TeamLinkt Widgets"): 3+ Cards, majority
     * have image + href set. Distinguished from people-directory
     * by the href-dominance signal that looksLikePeopleDirectory
     * REJECTS. Scraped logos + hrefs are discarded — the contract's
     * widgets are placed, never filled — but a diagnostic records
     * what was in the source for the reviewer.
     *
     * @param  array<int, array<string, mixed>>  $children
     */
    private function looksLikeSponsorDeck(array $children): bool
    {
        if (count($children) < 3) {
            return false;
        }
        $cardsWithImage = 0;
        $cardsWithHref = 0;
        foreach ($children as $child) {
            if (! is_array($child) || ($child['type'] ?? null) !== 'Card') {
                return false;
            }
            $props = is_array($child['props'] ?? null) ? $child['props'] : [];
            if (is_string($props['image'] ?? null) && $props['image'] !== '') {
                $cardsWithImage++;
            }
            if (is_string($props['href'] ?? null) && trim($props['href']) !== '') {
                $cardsWithHref++;
            }
        }
        $half = (int) ceil(count($children) / 2);

        // Sponsor characteristic: majority have BOTH image (logo)
        // and href (outbound link, even placeholder #).
        return $cardsWithImage >= $half && $cardsWithHref >= $half;
    }

    /**
     * News-list pattern: 3+ Cards where each Card looks like a news
     * article — title (headline), body (summary), image (article
     * photo), and href pointing to a real article URL (not sponsor-
     * shape placeholder "#" or scheme-less). Distinct from sponsor-
     * deck by: hrefs are heterogeneous real URLs, not placeholders;
     * bodies are prose (>80 chars typical), not "Visit Website"
     * CTAs.
     *
     * @param  array<int, array<string, mixed>>  $children
     */
    private function looksLikeNewsList(array $children): bool
    {
        if (count($children) < 3) {
            return false;
        }
        $realHrefs = 0;
        $longBodies = 0;
        foreach ($children as $child) {
            if (! is_array($child) || ($child['type'] ?? null) !== 'Card') {
                return false;
            }
            $props = is_array($child['props'] ?? null) ? $child['props'] : [];
            $href = is_string($props['href'] ?? null) ? trim($props['href']) : '';
            $body = is_string($props['body'] ?? null) ? $props['body'] : '';
            // Real href = http(s):// AND not a sponsor CTA pattern.
            if (preg_match('#^https?://#i', $href) === 1) {
                $realHrefs++;
            }
            // News summaries tend to be prose paragraphs; sponsor
            // CTAs are short ("Visit Website", "Learn more").
            if (strlen(trim($body)) >= 80) {
                $longBodies++;
            }
        }
        $half = (int) ceil(count($children) / 2);

        // BOTH signals required to distinguish from sponsor decks.
        return $realHrefs >= $half && $longBodies >= $half;
    }

    /**
     * @param  array<int, array<string, mixed>>  $cards
     */
    private function emitNewsList(array $cards, ?string $srcUrl): MappedContent
    {
        $summary = [];
        foreach ($cards as $card) {
            $props = is_array($card['props'] ?? null) ? $card['props'] : [];
            $title = is_string($props['title'] ?? null) ? $props['title'] : '';
            if ($title !== '') {
                $summary[] = $title;
            }
        }

        return new MappedContent(
            blocks: [new Block(type: 'NewsList', props: [
                'id' => $this->id('newslist', $srcUrl ?? '', (string) count($cards)),
            ])],
            diagnostics: [new Diagnostic(
                severity: 'info',
                code: 'news_list_placed_widget',
                message: sprintf(
                    'Detected news-list pattern (%d article Cards). Placed a NewsList widget; scraped article summaries discarded — widgets read live from TeamLinkt.',
                    count($cards),
                ),
                sourceUrl: $srcUrl !== null ? $srcUrl : new Optional,
                droppedContent: implode(' · ', array_slice($summary, 0, 5)),
            )],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $cards
     */
    private function emitSponsors(array $cards, ?string $srcUrl): MappedContent
    {
        // Contract Part III: Sponsors block is a widget — placed,
        // never populated. Data (org's actual sponsors) lives in
        // TeamLinkt. What we scraped is DISCARDED but recorded so
        // the reviewer knows an existing sponsor row was detected.
        $droppedSummary = [];
        foreach ($cards as $card) {
            $props = is_array($card['props'] ?? null) ? $card['props'] : [];
            $title = is_string($props['title'] ?? null) ? $props['title'] : '';
            $href = is_string($props['href'] ?? null) ? trim($props['href']) : '';
            $droppedSummary[] = $title !== '' ? "{$title} ({$href})" : $href;
        }

        $block = new Block(type: 'Sponsors', props: [
            'id' => $this->id('sponsors', $srcUrl ?? '', (string) count($cards)),
        ]);

        return new MappedContent(
            blocks: [$block],
            diagnostics: [new Diagnostic(
                severity: 'info',
                code: 'sponsor_deck_placed_widget',
                message: sprintf(
                    'Detected sponsor deck (%d Cards with image + href). Placed a Sponsors widget; scraped logos/URLs discarded — widgets read live from TeamLinkt.',
                    count($cards),
                ),
                sourceUrl: $srcUrl !== null ? $srcUrl : new Optional,
                droppedContent: implode(' · ', array_slice($droppedSummary, 0, 10)),
            )],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $cards
     */
    private function emitTeamMembers(
        array $cards,
        int $sourceColumnCount,
        AssetContext $ctx,
        AssetLedger $ledger,
        ?string $srcUrl,
    ): MappedContent {
        $items = [];
        $memberDiagnostics = [];
        foreach ($cards as $i => $card) {
            $props = is_array($card['props'] ?? null) ? $card['props'] : [];
            $title = $this->sanitiser->plainText(is_string($props['title'] ?? null) ? $props['title'] : '');
            $body = is_string($props['body'] ?? null) ? $props['body'] : '';
            $image = is_string($props['image'] ?? null) ? $props['image'] : '';
            $href = is_string($props['href'] ?? null) ? trim($props['href']) : '';

            // Two Card shapes exist on tbirdhoops alone:
            //   Board-style:    title=name, body=role,  image=photo.
            //   Contacts-style: title=role, body="name\nemail\nphone",
            //                   image=empty.
            //
            // The differentiator: image populated → Board-style.
            // Both shapes fold to the same TeamMembers.items[] entry.
            $item = ['photo' => '', 'name' => '', 'role' => '', 'email' => '', 'bio' => ''];
            if ($image !== '') {
                // Board-style.
                $token = $this->tokenise($image, $ctx, $ledger, 'other', $title !== '' ? $title : null);
                if ($token !== null) {
                    $item['photo'] = $token;
                }
                $item['name'] = $title;
                $item['role'] = $this->sanitiser->plainText($body);
            } else {
                // Contacts-style.
                $item['role'] = $title;
                $lines = array_values(array_filter(
                    array_map('trim', preg_split('/\r?\n/', $body) ?: []),
                    static fn (string $l): bool => $l !== '',
                ));
                // Find email + separate name line.
                $emailLine = null;
                $nameCandidate = '';
                $residual = [];
                foreach ($lines as $line) {
                    if ($emailLine === null && preg_match('/\S+@\S+\.\S+/', $line, $m) === 1) {
                        $emailLine = $m[0];

                        continue;
                    }
                    if ($nameCandidate === '' && ! str_starts_with($line, '📞')) {
                        $nameCandidate = $line;

                        continue;
                    }
                    $residual[] = $line;
                }
                $item['name'] = $nameCandidate !== '' ? $this->sanitiser->plainText($nameCandidate) : $title;
                $item['email'] = $emailLine ?? '';
                if ($residual !== []) {
                    $item['bio'] = $this->sanitiser->plainText(implode(' · ', $residual));
                }
            }

            // Href on the Card sometimes carries a mailto: — capture if we don't already have one.
            if ($item['email'] === '' && str_starts_with(strtolower($href), 'mailto:')) {
                $item['email'] = substr($href, 7);
            }

            // A card with no name at all is a directory shape we
            // don't understand; drop with a diagnostic.
            if ($item['name'] === '' && $item['role'] === '') {
                $memberDiagnostics[] = new Diagnostic(
                    severity: 'info',
                    code: 'people_directory_item_skipped',
                    message: "Card {$i} in a people-directory Columns has neither name nor role; skipped.",
                    sourceUrl: $srcUrl !== null ? $srcUrl : new Optional,
                );

                continue;
            }
            $items[] = $item;
        }

        if ($items === []) {
            return new MappedContent(blocks: [], diagnostics: $memberDiagnostics);
        }

        // Clamp source column count to the TeamMembers enum [2,3,4].
        $columns = max(2, min(4, $sourceColumnCount));

        $block = new Block(type: 'TeamMembers', props: [
            'id' => $this->id('teammembers', $srcUrl ?? '', (string) count($items)),
            'columns' => $columns,
            'items' => $items,
        ]);

        return new MappedContent(
            blocks: [$block],
            diagnostics: array_merge([
                new Diagnostic(
                    severity: 'info',
                    code: 'columns_folded_to_team_members',
                    message: sprintf(
                        'Detected people-directory pattern (%d Cards in %d source columns). Folded to a single TeamMembers block — layout preserved via TeamMembers.columns.',
                        count($cards),
                        $sourceColumnCount,
                    ),
                    sourceUrl: $srcUrl !== null ? $srcUrl : new Optional,
                ),
            ], $memberDiagnostics),
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

    // ── near-miss diagnostics — visible signal when a fold ALMOST fired ──
    //
    // Each near-miss detector emits an info-severity diagnostic with:
    //   - a SPECIFIC code (per near-miss cause, not per fold) so
    //     Metabase can histogram tuning actions separately
    //   - the source page URL when known
    //   - a truncated body snippet (≤ NEAR_MISS_SNIPPET_MAX chars),
    //     because a code without an example is not actionable
    //
    // Called from mapText AFTER every fold attempt returned null, and
    // BEFORE the body sanitises to Text. False positives are worse
    // than no diagnostic — an ignored diagnostic is worse than none.
    // Every detector has explicit false-positive tests that pin the
    // gate against ordinary prose (mid-paragraph links, sentences
    // ending in `?`, narrative with occasional bold emphasis, etc.).
    private const NEAR_MISS_SNIPPET_MAX = 240;

    /**
     * @return array<int, Diagnostic>
     */
    private function collectNearMissDiagnostics(string $rawBody, ?string $sourcePageUrl): array
    {
        $out = [];
        foreach ([
            $this->detectStatTableNearMissInconsistentColumns($rawBody, $sourcePageUrl),
            $this->detectStatTableNearMissNoColumnSeparator($rawBody, $sourcePageUrl),
            $this->detectQaSectionNearMissInlineQuestions($rawBody, $sourcePageUrl),
            $this->detectQaSectionNearMissHeadingSingleQuestion($rawBody, $sourcePageUrl),
            $this->detectFeatureGridNearMissInterstitialProse($rawBody, $sourcePageUrl),
            $this->detectFileDownloadNearMissBelowGridThreshold($rawBody, $sourcePageUrl),
            $this->detectVideoNearMissBodyTooLong($rawBody, $sourcePageUrl),
        ] as $diagnostic) {
            if ($diagnostic !== null) {
                $out[] = $diagnostic;
            }
        }

        return $out;
    }

    private function nearMissDiagnostic(string $code, string $message, string $rawBody, ?string $sourcePageUrl): Diagnostic
    {
        $snippet = $this->truncateSnippet($rawBody);
        $withExample = $message.' Snippet: '.$snippet;

        return new Diagnostic(
            severity: 'info',
            code: $code,
            message: $withExample,
            sourceUrl: $sourcePageUrl !== null && $sourcePageUrl !== '' ? $sourcePageUrl : new Optional,
        );
    }

    private function truncateSnippet(string $body): string
    {
        $flat = trim(preg_replace('/\s+/', ' ', $body) ?? $body);
        if (mb_strlen($flat) <= self::NEAR_MISS_SNIPPET_MAX) {
            return $flat;
        }

        return mb_substr($flat, 0, self::NEAR_MISS_SNIPPET_MAX - 1).'…';
    }

    // — stat_table near-misses ————————————————————————————————————————

    // Fires when: bullet list with ≥ STAT_TABLE_MIN_ROWS items, at
    // least one row has ≥2 dash-separated columns, BUT the rows have
    // DIFFERENT column counts across the list.
    // Tuning action: widen the fold to accept modal column count with
    // padding (Slice B).
    private function detectStatTableNearMissInconsistentColumns(string $rawBody, ?string $sourcePageUrl): ?Diagnostic
    {
        $items = $this->extractListItems($rawBody);
        if (count($items) < self::STAT_TABLE_MIN_ROWS) {
            return null;
        }
        foreach ([' — ', ' - ', ' – ', ' | '] as $sep) {
            $counts = [];
            foreach ($items as $item) {
                $cells = array_map('trim', explode($sep, $item));
                $cells = array_values(array_filter($cells, static fn (string $c): bool => $c !== ''));
                if (count($cells) < self::STAT_TABLE_MIN_COLUMNS) {
                    continue 2;
                }
                $counts[] = count($cells);
            }
            $unique = array_unique($counts);
            if (count($unique) > 1) {
                return $this->nearMissDiagnostic(
                    code: 'stat_table_near_miss_inconsistent_columns',
                    message: sprintf(
                        'Bullet list with %d items has consistent `%s` separator but MIXED column counts %s — fold rejected. Consider tuning to modal-count-with-padding.',
                        count($items),
                        trim($sep),
                        json_encode(array_count_values($counts)),
                    ),
                    rawBody: $rawBody,
                    sourcePageUrl: $sourcePageUrl,
                );
            }
        }

        return null;
    }

    // Fires when: bullet list with ≥ STAT_TABLE_MIN_ROWS items, but NO
    // consistent column separator was found — the items are plain bullets.
    // Tuning action: probably NOT a stat_table candidate; may signal
    // reviewer should add a column-separator to source or leave as list.
    private function detectStatTableNearMissNoColumnSeparator(string $rawBody, ?string $sourcePageUrl): ?Diagnostic
    {
        $items = $this->extractListItems($rawBody);
        if (count($items) < self::STAT_TABLE_MIN_ROWS) {
            return null;
        }
        // If any separator matched (some cells with ≥ MIN_COLUMNS), the
        // inconsistent-columns detector would have fired instead. This
        // one is for the plain-bullet-list case.
        foreach ([' — ', ' - ', ' – ', ' | '] as $sep) {
            foreach ($items as $item) {
                $cells = array_map('trim', explode($sep, $item));
                $cells = array_values(array_filter($cells, static fn (string $c): bool => $c !== ''));
                if (count($cells) >= self::STAT_TABLE_MIN_COLUMNS) {
                    return null; // some column shape present — not this near-miss
                }
            }
        }

        return $this->nearMissDiagnostic(
            code: 'stat_table_near_miss_no_column_separator',
            message: sprintf(
                'Bullet list with %d items but no consistent column separator between fields — plain single-column list, not tabular.',
                count($items),
            ),
            rawBody: $rawBody,
            sourcePageUrl: $sourcePageUrl,
        );
    }

    // — qa_section near-misses ————————————————————————————————————————

    // Fires when: body contains ≥ QA_MIN_ITEMS_STANDALONE `**...?**`
    // occurrences, but NONE are whole-line. Suggests block-fill emitted
    // inline emphasis when it should have emitted line-standalone
    // questions, or that source uses a different Q-marker convention.
    // Tuning action: block-fill prompt guidance may need reinforcement.
    private function detectQaSectionNearMissInlineQuestions(string $rawBody, ?string $sourcePageUrl): ?Diagnostic
    {
        $lines = preg_split('/\r?\n/', $rawBody);
        if ($lines === false) {
            return null;
        }
        $inlineCount = 0;
        $wholeLineCount = 0;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (preg_match('/^\*\*[^*]+\?\s*\*\*\s*$/', $trimmed) === 1) {
                $wholeLineCount++;

                continue;
            }
            // Inline `**anything?**` fragments mid-line.
            $matches = preg_match_all('/\*\*[^*]+\?\*\*/', $trimmed);
            if ($matches !== false && $matches > 0) {
                $inlineCount += $matches;
            }
        }
        if ($wholeLineCount > 0 || $inlineCount < self::QA_MIN_ITEMS_STANDALONE) {
            return null;
        }

        return $this->nearMissDiagnostic(
            code: 'qa_section_near_miss_inline_questions',
            message: sprintf(
                'Body contains %d `**...?**` fragments but NONE are whole-line — fold requires standalone question lines to fire.',
                $inlineCount,
            ),
            rawBody: $rawBody,
            sourcePageUrl: $sourcePageUrl,
        );
    }

    // Fires when: explicit "Frequently Asked Questions" heading is
    // present but ONLY 1 whole-line `**?**` follows (below the with-
    // heading threshold of 2).
    // Tuning action: source may have Q-content phrased differently
    // (e.g. plain question sentences, not bold-wrapped).
    private function detectQaSectionNearMissHeadingSingleQuestion(string $rawBody, ?string $sourcePageUrl): ?Diagnostic
    {
        $lines = preg_split('/\r?\n/', $rawBody);
        if ($lines === false) {
            return null;
        }
        $hasFaqHeading = false;
        $wholeLineCount = 0;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (preg_match('/^#+\s+(frequently\s+asked\s+questions|faq)\s*$/i', $trimmed) === 1) {
                $hasFaqHeading = true;

                continue;
            }
            if (preg_match('/^\*\*[^*]+\?\s*\*\*\s*$/', $trimmed) === 1) {
                $wholeLineCount++;
            }
        }
        if (! $hasFaqHeading || $wholeLineCount !== 1) {
            return null;
        }

        return $this->nearMissDiagnostic(
            code: 'qa_section_near_miss_heading_single_question',
            message: 'FAQ heading present but only 1 whole-line question — need ≥2 with heading (or ≥3 without) for Accordion/FAQ fold.',
            rawBody: $rawBody,
            sourcePageUrl: $sourcePageUrl,
        );
    }

    // — feature_grid near-miss ————————————————————————————————————————

    // Fires when: body has ≥ FEATURE_GRID_MIN_ITEMS link-only lines
    // BUT interstitial non-link, non-heading prose disqualified the
    // fold. Suggests block-fill added narrative around the link grid
    // that could be moved to a separate Text block above/below.
    private function detectFeatureGridNearMissInterstitialProse(string $rawBody, ?string $sourcePageUrl): ?Diagnostic
    {
        $lines = preg_split('/\r?\n/', $rawBody);
        if ($lines === false) {
            return null;
        }
        $linkOnlyCount = 0;
        $interstitialProseCount = 0;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (preg_match('/^(?:[-*]|\d+\.)\s+\[[^\]]+\]\([^)]+\)\s*$/', $trimmed) === 1
                || preg_match('/^#+\s+\[[^\]]+\]\([^)]+\)\s*$/', $trimmed) === 1
                || preg_match('/^\[[^\]]+\]\([^)]+\)\s*$/', $trimmed) === 1) {
                $linkOnlyCount++;

                continue;
            }
            // Non-link, non-blank prose. Section headings (no link inside)
            // count too since the fold allows one leading heading.
            if (preg_match('/^#+\s+[^\[]/', $trimmed) === 1 && $linkOnlyCount === 0) {
                // Leading section heading — allowed by the fold.
                continue;
            }
            $interstitialProseCount++;
        }
        if ($linkOnlyCount < self::FEATURE_GRID_MIN_ITEMS || $interstitialProseCount === 0) {
            return null;
        }

        return $this->nearMissDiagnostic(
            code: 'feature_grid_near_miss_interstitial_prose',
            message: sprintf(
                'Body has %d link-only lines that would fold to FeatureGrid, but %d interstitial prose line(s) disqualified the fold.',
                $linkOnlyCount,
                $interstitialProseCount,
            ),
            rawBody: $rawBody,
            sourcePageUrl: $sourcePageUrl,
        );
    }

    // — file_download near-miss ————————————————————————————————————————

    // Fires when: body has 2 doc-link headings (below FeatureGrid's ≥3
    // and above FileDownload's exact 1). Falls through to Text today.
    // Tuning action: either widen FileDownload to allow 2 or lower
    // FeatureGrid's threshold to 2 for pure-doc grids.
    private function detectFileDownloadNearMissBelowGridThreshold(string $rawBody, ?string $sourcePageUrl): ?Diagnostic
    {
        $lines = preg_split('/\r?\n/', $rawBody);
        if ($lines === false) {
            return null;
        }
        $docLinkCount = 0;
        $nonDocContentSeen = false;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            $matched = false;
            foreach ([
                '/^(?:[-*]|\d+\.)\s+\[([^\]]+)\]\(([^)]+)\)\s*$/',
                '/^#+\s+\[([^\]]+)\]\(([^)]+)\)\s*$/',
                '/^\[([^\]]+)\]\(([^)]+)\)\s*$/',
            ] as $pattern) {
                if (preg_match($pattern, $trimmed, $m) === 1) {
                    $matched = true;
                    if ($this->looksLikeDocumentUrl($m[2])) {
                        $docLinkCount++;
                    } else {
                        $nonDocContentSeen = true;
                    }
                    break;
                }
            }
            if (! $matched) {
                $nonDocContentSeen = true;
            }
        }
        if ($docLinkCount !== 2 || $nonDocContentSeen) {
            return null;
        }

        return $this->nearMissDiagnostic(
            code: 'file_download_near_miss_below_grid_threshold',
            message: 'Body has 2 document-link headings — below FeatureGrid\'s ≥3 threshold and above FileDownload\'s exact 1. Landed as Text.',
            rawBody: $rawBody,
            sourcePageUrl: $sourcePageUrl,
        );
    }

    // — video near-miss ————————————————————————————————————————————————

    // Fires when: body contains a YouTube/Vimeo URL but exceeds
    // VIDEO_MAX_LINES non-blank lines. Video fold requires a compact
    // body — anything longer means a video URL is mentioned inline
    // (which we'd rather not spuriously fold) or block-fill wrapped
    // the URL in more prose than the fold accepts.
    private function detectVideoNearMissBodyTooLong(string $rawBody, ?string $sourcePageUrl): ?Diagnostic
    {
        if (preg_match(self::VIDEO_URL_PATTERN, $rawBody) !== 1) {
            return null;
        }
        $lines = preg_split('/\r?\n/', $rawBody);
        if ($lines === false) {
            return null;
        }
        $nonBlank = array_values(array_filter(
            array_map('trim', $lines),
            static fn (string $l): bool => $l !== '',
        ));
        if (count($nonBlank) <= self::VIDEO_MAX_LINES) {
            return null;
        }

        return $this->nearMissDiagnostic(
            code: 'video_near_miss_body_too_long',
            message: sprintf(
                'Body contains a YouTube/Vimeo URL but has %d non-blank lines (fold requires ≤%d).',
                count($nonBlank),
                self::VIDEO_MAX_LINES,
            ),
            rawBody: $rawBody,
            sourcePageUrl: $sourcePageUrl,
        );
    }

    // IR concept `video` — fold detection.
    //
    // Detection gate:
    //   - Body ≤ VIDEO_MAX_LINES non-blank lines (compact — a hero
    //     paragraph with a video URL isn't a video block).
    //   - Contains a YouTube (youtube.com/watch, youtu.be, embed) or
    //     Vimeo (vimeo.com) URL, either as a markdown link or bare.
    //   - First matching URL becomes Video.url.
    //   - Optional non-URL line becomes Video.caption.text.
    //
    // Adjacent patterns that MUST NOT fold:
    //   - Prose paragraph with an inline video URL mentioned mid-
    //     sentence: > VIDEO_MAX_LINES worth of content disqualifies.
    //   - Bare URL in a news article body: has surrounding prose,
    //     fails the compact-body gate.
    //   - Card with attribution text but no URL (current block-fill
    //     output for cjfl "Under the Helmet" — body dropped the URL):
    //     mapCard doesn't route through mapText, so no risk here.
    private const VIDEO_MAX_LINES = 3;

    private const VIDEO_URL_PATTERN = '#https?://(?:(?:www\.)?youtube\.com/(?:watch\?v=|embed/)|youtu\.be/|(?:www\.)?vimeo\.com/)[A-Za-z0-9_\-?&=/.]+#i';

    private function tryFoldToVideo(string $rawBody): ?Block
    {
        $lines = preg_split('/\r?\n/', $rawBody);
        if ($lines === false) {
            return null;
        }
        $nonBlank = array_values(array_filter(
            array_map('trim', $lines),
            static fn (string $l): bool => $l !== '',
        ));
        if (count($nonBlank) === 0 || count($nonBlank) > self::VIDEO_MAX_LINES) {
            return null;
        }

        $videoUrl = null;
        $captionLines = [];
        foreach ($nonBlank as $line) {
            if ($videoUrl === null && preg_match(self::VIDEO_URL_PATTERN, $line, $m) === 1) {
                $videoUrl = $m[0];

                continue;
            }
            $captionLines[] = $line;
        }
        if ($videoUrl === null) {
            return null;
        }
        $caption = trim(implode(' ', $captionLines));
        // Strip markdown link text if the caption was itself a link.
        $caption = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $caption) ?? $caption;

        $props = [
            'id' => $this->id('video', $videoUrl),
            'url' => $videoUrl,
        ];
        if ($caption !== '') {
            $props['caption'] = ['text' => $caption];
        }

        return new Block(type: 'Video', props: $props);
    }

    // IR concept `file_download` — fold detection.
    //
    // Detection gate:
    //   - Body has exactly ONE non-blank line.
    //   - That line is a heading/bulleted/bare link (same shapes
    //     FeatureGrid accepts, but a single one).
    //   - The link URL ends in a document extension: .pdf, .doc,
    //     .docx, .xls, .xlsx, .ppt, .pptx.
    //
    // Adjacent patterns that MUST NOT fold:
    //   - Inline doc links inside prose (URL mentioned mid-sentence):
    //     the body has more than one non-blank line.
    //   - Multi-doc-link pages (langdon For Coaches, cjfl Rules): ≥3
    //     doc links fold to FeatureGrid instead (this method returns
    //     null because it requires exactly one line).
    //   - Non-document URL as a single heading link: stays Text
    //     (heading-with-URL isn't automatically a "download").
    private const FILE_DOWNLOAD_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];

    private function tryFoldToFileDownload(string $rawBody): ?Block
    {
        $lines = preg_split('/\r?\n/', $rawBody);
        if ($lines === false) {
            return null;
        }
        $nonBlank = array_values(array_filter(
            array_map('trim', $lines),
            static fn (string $l): bool => $l !== '',
        ));
        if (count($nonBlank) !== 1) {
            return null;
        }
        $line = $nonBlank[0];

        $patterns = [
            '/^(?:[-*]|\d+\.)\s+\[([^\]]+)\]\(([^)]+)\)\s*$/',
            '/^#+\s+\[([^\]]+)\]\(([^)]+)\)\s*$/',
            '/^\[([^\]]+)\]\(([^)]+)\)\s*$/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line, $m) === 1) {
                $label = trim($m[1]);
                $href = trim($m[2]);
                if (! $this->looksLikeDocumentUrl($href) || $label === '') {
                    return null;
                }

                return new Block(type: 'FileDownload', props: [
                    'id' => $this->id('file-download', $label, $href),
                    'fileUrl' => $href,
                    'label' => $label,
                ]);
            }
        }

        return null;
    }

    private function looksLikeDocumentUrl(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '') {
            return false;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, self::FILE_DOWNLOAD_EXTENSIONS, true);
    }

    // IR concept `feature_grid` — fold detection.
    //
    // Detection gate:
    //   - Every non-blank line in the body matches a link-only shape:
    //       * bulleted:  `^- [text](url)$` or `^* [text](url)$`
    //       * heading:   `^#+ [text](url)$`
    //       * bare:      `^[text](url)$`
    //   - ≥ FEATURE_GRID_MIN_ITEMS such lines total.
    //   - No prose paragraphs between the link lines. Any non-blank
    //     line that isn't a link-only line disqualifies (falls
    //     through to normal Text).
    //
    // Adjacent patterns that MUST NOT fold:
    //   - Sponsors / TeamMembers / NewsList / Grid folds all detect
    //     Card sequences inside Columns.columns[].children. Our
    //     detection is at the Text-body level — the source shapes
    //     don't overlap.
    //   - stat_table records have dash-separated columns; link-only
    //     lines have no separator between the text and the URL is
    //     part of the anchor, not a column.
    //   - FAQ has whole-line `**?**` questions; link lines don't.
    //   - Prose with occasional links falls through — the "every
    //     line is a link" gate is strict.
    private const FEATURE_GRID_MIN_ITEMS = 3;

    private function tryFoldToFeatureGrid(string $rawBody): ?Block
    {
        $lines = preg_split('/\r?\n/', $rawBody);
        if ($lines === false || $lines === []) {
            return null;
        }
        $items = [];
        $preheading = null;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            // Optional single leading section heading that isn't a link
            // itself — treat as the FeatureGrid.heading prop.
            if ($preheading === null && $items === []
                && preg_match('/^#+\s+([^\[].*)$/', $trimmed, $m) === 1) {
                $preheading = trim($m[1]);

                continue;
            }
            // Bulleted / numbered link line.
            if (preg_match('/^(?:[-*]|\d+\.)\s+\[([^\]]+)\]\(([^)]+)\)\s*$/', $trimmed, $m) === 1) {
                $items[] = ['title' => trim($m[1]), 'href' => trim($m[2])];

                continue;
            }
            // Heading link line.
            if (preg_match('/^#+\s+\[([^\]]+)\]\(([^)]+)\)\s*$/', $trimmed, $m) === 1) {
                $items[] = ['title' => trim($m[1]), 'href' => trim($m[2])];

                continue;
            }
            // Bare link line.
            if (preg_match('/^\[([^\]]+)\]\(([^)]+)\)\s*$/', $trimmed, $m) === 1) {
                $items[] = ['title' => trim($m[1]), 'href' => trim($m[2])];

                continue;
            }

            // Anything else disqualifies — no interstitial prose.
            return null;
        }
        if (count($items) < self::FEATURE_GRID_MIN_ITEMS) {
            return null;
        }

        // Contract's FeatureGrid.items has {icon?, title, body?}.
        // hrefs don't fit; the block itself is not linkable. Encode
        // the href by prefixing the title with the raw URL so the
        // reviewer sees which page the item points at. A future
        // slice can promote hrefs when FeatureGrid grows a link prop.
        $gridItems = [];
        foreach ($items as $item) {
            $gridItems[] = [
                'title' => $item['title'],
                'body' => $item['href'],
            ];
        }
        // Clamp columns to the enum [2,3,4]. Pick 3 as the default
        // (matches the schema's own defaults blob).
        $columns = match (true) {
            count($items) <= 4 => 2,
            count($items) <= 9 => 3,
            default => 4,
        };
        $props = [
            'id' => $this->id('feature-grid', ...array_column($items, 'title')),
            'items' => $gridItems,
            'columns' => $columns,
        ];
        if ($preheading !== null && $preheading !== '') {
            $props['heading'] = $preheading;
        }

        return new Block(type: 'FeatureGrid', props: $props);
    }

    // IR concept `qa_section` — fold detection.
    //
    // Detection gate (either A or B):
    //   A. Body contains ≥ QA_MIN_ITEMS_STANDALONE `**Question?**`
    //      bold-question markers on their own lines, each followed by
    //      non-empty answer prose (answer runs until the next question
    //      or end of body).
    //   B. Body contains a "Frequently Asked Questions" / "FAQ" heading
    //      (level ≥ 2) AND ≥ QA_MIN_ITEMS_WITH_HEADING bold-question
    //      markers.
    //
    // Target block (chosen from PAGE CONTEXT):
    //   - Source URL slug matches /(^|/)(faq|frequently-asked-questions)/
    //     → FAQ block (dedicated FAQ page)
    //   - Otherwise → Accordion block (Q&A section within a broader page)
    //
    // Question line pattern: `^\s*\*\*[^*]+\?\s*\*\*\s*$` (a whole-line
    // bold-wrapped text ending in `?`). This won't false-match
    // inline `**Bold**` emphasis mid-paragraph (which lacks the `?`
    // AND isn't on its own line).
    //
    // Answer body is RICHTEXT for BOTH targets — Accordion.items[].body
    // and FAQ.items[].body are both in x-teamlinkt.vocabularies.richtext.props.
    // The sanitiser applies uniformly.
    //
    // Adjacent patterns that MUST NOT fold:
    //   - stat_table (bulleted record rows): no `**?**` questions.
    //   - FeatureGrid (link-heading grid): no `?` in the headings.
    //   - Prose with occasional bold emphasis: bold isn't line-only
    //     and doesn't end with `?`.
    private const QA_MIN_ITEMS_STANDALONE = 3;

    private const QA_MIN_ITEMS_WITH_HEADING = 2;

    private function tryFoldToQaSection(string $rawBody, ?string $sourcePageUrl): ?QaSectionFold
    {
        // Split into lines; look for the boundary markers.
        $lines = preg_split('/\r?\n/', $rawBody);
        if ($lines === false || $lines === []) {
            return null;
        }

        $questionLineIdx = [];
        $hasFaqHeading = false;
        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            // Heading match — any level, mentioning "Frequently Asked
            // Questions" or standalone "FAQ".
            if (preg_match('/^#+\s+(frequently\s+asked\s+questions|faq)\s*$/i', $trimmed) === 1) {
                $hasFaqHeading = true;

                continue;
            }
            // Question line — whole line is a bold-wrapped question.
            if (preg_match('/^\*\*[^*]+\?\s*\*\*\s*$/', $trimmed) === 1) {
                $questionLineIdx[] = $i;
            }
        }

        $threshold = $hasFaqHeading ? self::QA_MIN_ITEMS_WITH_HEADING : self::QA_MIN_ITEMS_STANDALONE;
        if (count($questionLineIdx) < $threshold) {
            return null;
        }

        // Assemble items: for each Q line, capture answer prose between
        // this Q and the next Q (or end).
        $items = [];
        $count = count($questionLineIdx);
        for ($k = 0; $k < $count; $k++) {
            $start = $questionLineIdx[$k];
            $end = $k + 1 < $count ? $questionLineIdx[$k + 1] : count($lines);
            $qLine = trim($lines[$start]);
            // Strip the surrounding ** and trailing ? for the title.
            $title = trim(preg_replace('/^\*\*|\*\*$/', '', $qLine) ?? $qLine);
            $answerLines = array_slice($lines, $start + 1, $end - $start - 1);
            $answerRaw = trim(implode("\n", $answerLines));
            $answerHtml = $this->sanitiser->sanitize($answerRaw);
            if ($title === '') {
                continue;
            }
            $items[] = [
                'title' => $title,
                'body' => $answerHtml,
            ];
        }

        if (count($items) < $threshold) {
            return null;
        }

        // Target block from page context. FAQ = dedicated FAQ page
        // (slug indicates FAQ); Accordion = section-within-page (default).
        $isDedicatedFaqPage = $this->urlIndicatesFaqPage($sourcePageUrl);
        $type = $isDedicatedFaqPage ? 'FAQ' : 'Accordion';
        $idPrefix = $isDedicatedFaqPage ? 'faq' : 'accordion';
        $block = new Block(type: $type, props: [
            'id' => $this->id($idPrefix, ...array_column($items, 'title')),
            'items' => $items,
        ]);

        return new QaSectionFold($block);
    }

    private function urlIndicatesFaqPage(?string $url): bool
    {
        if ($url === null || $url === '') {
            return false;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '') {
            return false;
        }

        return preg_match('#(?:^|/)(faq|frequently-asked-questions|faqs)(?:/|$)#i', $path) === 1;
    }

    // IR concept `stat_table` — fold detection.
    //
    // Detection gate (ALL must hold):
    //   1. Body has ≥ MIN_ROWS list items (bulleted `- `/`* ` or
    //      numbered `\d+. `) on their own lines.
    //   2. All items split on the SAME separator into the SAME column
    //      count C, C ≥ MIN_COLUMNS.
    //   3. Separator preference: em-dash then hyphen then en-dash then
    //      pipe, first one that satisfies (2) wins. Space-padded only;
    //      a bare `-` inside prose ("St. Clair") must not match.
    //   4. Column values are trimmed non-empty.
    //
    // Why strict: adjacent patterns must NOT fold. Regression signals:
    //   - FeatureGrid link-heading grid (`### [Awards Page](url)`):
    //     no list items, so gate (1) fails.
    //   - FAQ Q/A (`**Question?**\n\nAnswer text.`): not list items.
    //   - Prose bullet list ("What We Ask of Our Families"): items don't
    //     split on a shared separator into multiple columns.
    //   - Short bullet list (< MIN_ROWS): mundane bullet content.
    //
    // Positive fixture: cjfl Larry Wruck (19 rows × 4 cols on ` - `),
    //                    cjfl Peter Dalla Riva (19 rows × 4 cols on ` — `).
    private const STAT_TABLE_MIN_ROWS = 5;

    private const STAT_TABLE_MIN_COLUMNS = 2;

    private function tryFoldToTable(string $rawBody): ?Block
    {
        $items = $this->extractListItems($rawBody);
        if (count($items) < self::STAT_TABLE_MIN_ROWS) {
            return null;
        }

        // First separator that splits every item into the same ≥2 columns wins.
        foreach ([' — ', ' - ', ' – ', ' | '] as $sep) {
            $rows = $this->splitRowsOn($items, $sep);
            if ($rows === null) {
                continue;
            }

            return $this->buildTableBlock($rows);
        }

        return null;
    }

    /**
     * @return array<int, string> trimmed inner text of each list item; empty when the body isn't a list
     */
    private function extractListItems(string $body): array
    {
        $lines = preg_split('/\r?\n/', $body);
        if ($lines === false) {
            return [];
        }
        $items = [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '') {
                continue;
            }
            if (preg_match('/^([-*]|\d+\.)\s+(.+)$/', $trimmed, $m) !== 1) {
                // Any non-list, non-blank line disqualifies — a
                // record-list block shouldn't have interstitial prose.
                return [];
            }
            $items[] = trim($m[2]);
        }

        return $items;
    }

    /**
     * Slice B: gate accepts variable column counts across rows. Each
     * row must have ≥ MIN_COLUMNS cells; padding to max happens in
     * buildTableBlock. This ragged-tolerant behavior handles cjfl's
     * award-history pages where older rows are missing the tail
     * position column (post-Larry-Wruck-hedge finding).
     *
     * @param  array<int, string>  $items
     * @param  non-empty-string  $sep
     * @return array<int, array<int, string>>|null N rows × variable cells, or null if any row has fewer than MIN_COLUMNS
     */
    private function splitRowsOn(array $items, string $sep): ?array
    {
        $rows = [];
        foreach ($items as $item) {
            $cells = array_map('trim', explode($sep, $item));
            $cells = array_values(array_filter($cells, static fn (string $c): bool => $c !== ''));
            if (count($cells) < self::STAT_TABLE_MIN_COLUMNS) {
                return null;
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    /**
     * Pad every row to MAX column count with empty-content cells at
     * the end. Contract's Table cells[] is length-unconstrained by
     * schema (ragged rows are structurally valid), but a UI-rendered
     * table looks cleaner uniform. Padding at the END matches record
     * data's natural shape (missing tail-column data — like older
     * award rows without a position column).
     *
     * Never truncate: rows longer than max don't exist by construction
     * (max is derived from rows themselves) and no data is dropped.
     *
     * @param  array<int, array<int, string>>  $rows
     */
    private function buildTableBlock(array $rows): Block
    {
        $maxCols = 0;
        foreach ($rows as $cells) {
            $maxCols = max($maxCols, count($cells));
        }

        $rowsOut = [];
        foreach ($rows as $r => $cells) {
            $cellsOut = [];
            for ($c = 0; $c < $maxCols; $c++) {
                $cellText = $cells[$c] ?? '';
                $cellsOut[] = [
                    'content' => [[
                        'type' => 'Text',
                        'props' => [
                            'id' => $this->id('text', "table.{$r}.{$c}:{$cellText}"),
                            'body' => $cellText,
                            'as' => 'p',
                        ],
                    ]],
                ];
            }
            $rowsOut[] = ['cells' => $cellsOut];
        }

        return new Block(type: 'Table', props: [
            'id' => $this->id('table', ...array_merge(...$rows)),
            'rows' => $rowsOut,
            // Source didn't distinguish a header row; leave as data-only
            // so a reviewer can promote the first row if they want one.
            'hasHeaderRow' => false,
        ]);
    }
}
