<?php

declare(strict_types=1);

namespace Tests\Unit\Plan;

use App\Data\AssetRef;
use App\Data\Brand;
use App\Data\ContentRef;
use App\Data\DecisionAction;
use App\Data\DecisionEntry;
use App\Data\Manifest;
use App\Data\NavNode;
use App\Data\SiteStructure;
use App\Services\Generate\ContentLoader;
use App\Services\Plan\RootNavPlanner;
use App\Services\Plan\SePlatformContentDetector;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\DataCollection;
use Tests\Support\Plan\FakeClassifierAgent;
use Tests\TestCase;

// Integration test against the REAL on-disk tbirdhoops corpus.
//
// storage/app/private/orgs/ngin-63620/scrapes/ holds 9 chrome-free
// Firecrawl scrapes captured during INGEST. Two of them are SE-templated
// tutorial content (SE Parents, Unsubscribe); the other 7 are genuine org
// pages. This test wires the actual on-disk bodies through the actual
// ContentLoader + actual detector + actual RootNavPlanner phase 1.5 and
// asserts the SE-platform-content park fires EXACTLY on those two and on
// no others.
//
// Loud failures here mean either the detector regressed or a phase 1.5
// integration issue exists — both are worth catching immediately.
final class SePlatformContentParkTest extends TestCase
{
    private const CORPUS_DIR = 'orgs/ngin-63620/scrapes';

    private const ORG_BASE_URL = 'https://www.tbirdhoops.org';

    // Both pages must end up parked. They take different routes:
    //
    //   /parent-help                                — caught in phase 1 by
    //     the EXISTING label rule (page label "SportsEngine Parents"
    //     matches /sports\s*engine/). Ledger reason: "SE platform link…".
    //     The body-content detector would also catch it — the unit test
    //     covers that on the real corpus — but in production it never
    //     gets past phase 1.
    //
    //   /page/show/8659687-how-to-unsubscribe       — label is "How To
    //     Unsubscribe", no SE word; phase 1's label rule misses it.
    //     This is what the new body-content detector catches. Ledger
    //     reason contains "se_platform_content".
    private const PHASE_1_PARKED_PATH = '/parent-help';

    private const PHASE_1_5_PARKED_PATH = '/page/show/8659687-how-to-unsubscribe';

    #[Test]
    public function tbirdhoops_parks_exactly_the_two_se_platform_pages_and_keeps_the_other_seven(): void
    {
        $manifest = $this->buildTbirdhoopsManifest();

        $agent = new FakeClassifierAgent;
        $plan = (new RootNavPlanner(
            $agent,
            new ContentLoader(disk: 'local'),
            new SePlatformContentDetector,
        ))->plan($manifest);

        /** @var array<int, DecisionEntry> $entries */
        $entries = $plan->ledger->entries->items();

        // Phase 1: SE Parents parked deterministically by the existing
        // label rule.
        $seParents = $this->entryForTarget($entries, self::PHASE_1_PARKED_PATH);
        $this->assertNotNull($seParents);
        $this->assertSame(DecisionAction::Park, $seParents->action);
        $this->assertStringContainsString('SE platform link', $seParents->reason);

        // Phase 1.5: Unsubscribe parked by the new body-content detector.
        $unsub = $this->entryForTarget($entries, self::PHASE_1_5_PARKED_PATH);
        $this->assertNotNull($unsub);
        $this->assertSame(DecisionAction::Park, $unsub->action);
        $this->assertStringContainsString('se_platform_content', $unsub->reason);

        // Every OTHER captured page is Keep'd. This proves the detector
        // didn't over-fire — the conservative thresholds hold on real data.
        foreach ($this->corpusPaths() as $path) {
            if ($path === self::PHASE_1_PARKED_PATH || $path === self::PHASE_1_5_PARKED_PATH) {
                continue;
            }
            $entry = $this->entryForTarget($entries, $path);
            $this->assertNotNull($entry);
            $this->assertSame(
                DecisionAction::Keep,
                $entry->action,
                "{$path} must stay Keep — detector over-fired or another rule incorrectly parked it. ".
                "Got action={$entry->action->value}, reason={$entry->reason}"
            );
        }

        // Phase 1.5 runs BEFORE the LLM phase — neither parked page should
        // appear in the classifier's seen log.
        $seenLabels = array_map(static fn ($p): string => $p->label, $agent->seen);
        $this->assertNotContains('SportsEngine Parents', $seenLabels, 'SE Parents must be parked deterministically, not sent to the LLM');
        $this->assertNotContains('How To Unsubscribe', $seenLabels, 'Unsubscribe must be parked by phase 1.5, not sent to the LLM');
    }

    #[Test]
    public function phase_1_5_ledger_reason_carries_loud_signal_values(): void
    {
        // The reviewer must be able to read EXACTLY why phase 1.5 parked a
        // page — the counts, the ratio, AND the matched vocab phrases all
        // surface in the text. This is the unsafe-direction safety net:
        // false-park is destructive, so the reason has to make it
        // promotable-back-to-Keep with a quick read.
        $manifest = $this->buildTbirdhoopsManifest();
        $plan = (new RootNavPlanner(
            new FakeClassifierAgent,
            new ContentLoader(disk: 'local'),
            new SePlatformContentDetector,
        ))->plan($manifest);

        /** @var array<int, DecisionEntry> $entries */
        $entries = $plan->ledger->entries->items();

        $entry = $this->entryForTarget($entries, self::PHASE_1_5_PARKED_PATH);
        $this->assertNotNull($entry);

        $this->assertStringContainsString('se_platform_content', $entry->reason);
        $this->assertMatchesRegularExpression('/\d+ SE-tutorial links of \d+ total/', $entry->reason);
        $this->assertMatchesRegularExpression('/ratio \d\.\d{2}/', $entry->reason);
        $this->assertStringContainsString('vocab phrases: [', $entry->reason);
    }

    #[Test]
    public function phase_1_5_park_carries_high_confidence(): void
    {
        // Confidence on a deterministic SE-platform park is intentionally
        // high (the bar to fire is high). Downstream consumers reading the
        // ledger should know the decision is firm, not a wobble.
        $manifest = $this->buildTbirdhoopsManifest();
        $plan = (new RootNavPlanner(
            new FakeClassifierAgent,
            new ContentLoader(disk: 'local'),
            new SePlatformContentDetector,
        ))->plan($manifest);

        /** @var array<int, DecisionEntry> $entries */
        $entries = $plan->ledger->entries->items();

        $entry = $this->entryForTarget($entries, self::PHASE_1_5_PARKED_PATH);
        $this->assertNotNull($entry);
        $this->assertGreaterThanOrEqual(0.9, $entry->confidence);
    }

    // ─── helpers ────────────────────────────────────────────────────────

    /**
     * Build a Manifest directly from the on-disk tbirdhoops corpus. We
     * skip the extractor — the captured JSON files already exist, and
     * re-running the extractor would just rewrite them with the same
     * content. Each JSON contributes one NavNode + one ContentRef; nav
     * is flat (all pages top-level).
     */
    private function buildTbirdhoopsManifest(): Manifest
    {
        /** @var array<int, NavNode> $nodes */
        $nodes = [];
        /** @var array<int, ContentRef> $refs */
        $refs = [];

        $i = 0;
        foreach ($this->corpusFiles() as $filename => $data) {
            $url = $data['url'];
            $title = $data['title'];
            $relativeUrl = parse_url($url, PHP_URL_PATH) ?: '/'.$title;

            $nodes[] = new NavNode(
                label: $title,
                url: $relativeUrl,
                kind: 'page',
                children: new DataCollection(NavNode::class, []),
                node_type: 'Page',
                page_node_id: 7000000 + $i,
                external_subtype: null,
            );
            $refs[] = new ContentRef(
                url: $url,
                scrape_ref: 's3://'.self::CORPUS_DIR.'/'.$filename,
                title: $title,
                nav_path: [$title],
            );
            $i++;
        }

        return new Manifest(
            source_url: self::ORG_BASE_URL,
            org_id: 'ngin-63620',
            structure: new SiteStructure(
                nav: new DataCollection(NavNode::class, $nodes),
                pages_total: count($nodes),
            ),
            provisioning: null,
            brand: new Brand(logo_source: 'header'),
            content_refs: new DataCollection(ContentRef::class, $refs),
            asset_refs: new DataCollection(AssetRef::class, []),
            confidence: 1.0,
        );
    }

    /**
     * @return array<int, string> URL paths (relative form) of every page in the corpus
     */
    private function corpusPaths(): array
    {
        $out = [];
        foreach ($this->corpusFiles() as $data) {
            $path = parse_url($data['url'], PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                $out[] = $path;
            }
        }

        return $out;
    }

    /**
     * @return array<string, array{url: string, title: string, markdown: string}> filename => decoded scrape
     */
    private function corpusFiles(): array
    {
        $dir = storage_path('app/private/'.self::CORPUS_DIR);
        $files = glob($dir.'/*.json');
        if ($files === false) {
            return [];
        }
        sort($files);

        $out = [];
        foreach ($files as $path) {
            $raw = file_get_contents($path);
            if ($raw === false) {
                continue;
            }
            $data = json_decode($raw, true);
            if (! is_array($data)) {
                continue;
            }
            $out[basename($path)] = $data;
        }

        return $out;
    }

    /**
     * @param  array<int, DecisionEntry>  $entries
     */
    private function entryForTarget(array $entries, string $target): ?DecisionEntry
    {
        foreach ($entries as $entry) {
            if ($entry->target === $target) {
                return $entry;
            }
        }

        return null;
    }
}
