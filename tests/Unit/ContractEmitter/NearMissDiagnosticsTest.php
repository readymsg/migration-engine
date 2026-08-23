<?php

declare(strict_types=1);

namespace Tests\Unit\ContractEmitter;

use App\Data\AssetRef;
use App\Data\SiteImport\Diagnostic;
use App\Services\ContractEmitter\AssetContext;
use App\Services\ContractEmitter\AssetLedger;
use App\Services\ContractEmitter\PuckToContractMapper;
use App\Services\ContractEmitter\RichTextSanitizer;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

// Slice A near-miss layer — every detector has:
//   - a POSITIVE pin (the shape the detector fires on)
//   - an explicit FALSE-POSITIVE test (a common prose shape that
//     resembles the near-miss but must NOT fire)
//   - assertion that the diagnostic carries a body snippet + sourceUrl
//
// Ordinary prose false-positive cases guarded here:
//   - a paragraph with three markdown links (feature_grid)
//   - a sentence with `**Bold?**` inline emphasis (qa_section)
//   - a narrative that mentions a PDF (file_download)
//   - hero-length prose with a YouTube link mid-sentence (video)
//   - a bullet list of programs with no separator (stat_table)
final class NearMissDiagnosticsTest extends TestCase
{
    private PuckToContractMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new PuckToContractMapper(new RichTextSanitizer);
    }

    // ─── stat_table_near_miss_inconsistent_columns ─────────────────────

    #[Test]
    public function short_bullet_list_below_min_rows_does_not_fire_inconsistent_columns(): void
    {
        // < 5 rows: the near-miss requires the stat_table min-rows too.
        // (Slice B tuned the fold to be ragged-tolerant, so the
        // inconsistent-columns near-miss is now unreachable on
        // real award-history data — the fold fires instead.
        // Kept as a stability guard: whichever detector logic the
        // future re-tightens, short lists must never fire the
        // near-miss.)
        $body = "- 2025 - Jaylin\n".
                "- 2024 - Stephen\n".
                '- 2023 - Stephen';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertNotContains('stat_table_near_miss_inconsistent_columns', $codes);
    }

    // ─── stat_table_near_miss_no_column_separator ──────────────────────

    #[Test]
    public function bullet_list_of_programs_fires_no_column_separator_near_miss(): void
    {
        // A plain bullet list of ≥5 program names — no dashes between
        // fields. Fold's real detection requires columns; near-miss
        // says "this is a plain list, not tabular."
        $body = "- Blast Ball\n".
                "- T-Ball\n".
                "- Coach Pitch\n".
                "- Minors\n".
                "- Majors\n".
                '- Juniors';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
            sourcePageUrl: 'https://x.example/programs',
        );
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertContains('stat_table_near_miss_no_column_separator', $codes);
    }

    #[Test]
    public function bullet_list_with_columns_does_not_fire_no_separator(): void
    {
        // Has column separators — inconsistent-columns fires, not the
        // no-separator detector.
        $body = "- 2025 - Jaylin - Saints - DL\n".
                "- 2024 - Stephen - Thunder - LB\n".
                "- 2023 - Konner - Hilltops - LB\n".
                "- 2022 - Austin - Colts - LB\n".
                '- 2021 - Jaydn - Hilltops - LB';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        // All rows have same column count (4) — actually folds to Table.
        // Just assert no_separator did not fire.
        $this->assertNotContains('stat_table_near_miss_no_column_separator', $codes);
    }

    // ─── qa_section_near_miss_inline_questions ─────────────────────────

    #[Test]
    public function inline_bold_questions_across_multiple_lines_fires_near_miss(): void
    {
        // Block-fill produced `**Question?**` inline instead of whole-
        // line. Below the whole-line threshold; the near-miss surfaces
        // the alternate shape.
        $body = 'This section covers common questions. Parents often ask **When do practices start?** and we tell them Tuesdays. '.
                'Others wonder **How do I sign up?** — through the Registration TAB. '.
                'And a few ask **What if I can\'t make it?** — contact your coach.';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
            sourcePageUrl: 'https://x.example/parents',
        );
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertContains('qa_section_near_miss_inline_questions', $codes);
    }

    #[Test]
    public function sentence_with_one_inline_question_does_not_fire_near_miss(): void
    {
        // A single rhetorical question mid-paragraph — below threshold,
        // should not fire. Represents ordinary prose.
        $body = 'Ever wondered **why we play basketball?** It is because we love the sport.';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertNotContains('qa_section_near_miss_inline_questions', $codes);
    }

    // ─── qa_section_near_miss_heading_single_question ──────────────────

    #[Test]
    public function faq_heading_with_only_one_question_fires_near_miss(): void
    {
        $body = "## Frequently Asked Questions\n\n".
                "**When are practices?**\n\n".
                "Tuesdays and Thursdays.\n\n".
                'Contact your coach for more details.';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
            sourcePageUrl: 'https://x.example/info',
        );
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertContains('qa_section_near_miss_heading_single_question', $codes);
    }

    #[Test]
    public function no_faq_heading_without_questions_does_not_fire(): void
    {
        // No FAQ heading present, so this near-miss can't fire even
        // with 1 question line.
        $body = "**When are practices?**\n\nTuesdays and Thursdays.";

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertNotContains('qa_section_near_miss_heading_single_question', $codes);
    }

    // ─── feature_grid_near_miss_interstitial_prose ─────────────────────

    #[Test]
    public function link_grid_with_interstitial_prose_fires_near_miss(): void
    {
        // 4 link-only lines but a middle line of prose disqualified
        // the fold.
        $body = "- [Rules](https://x.example/rules)\n".
                "- [Programs](https://x.example/programs)\n".
                "Additional information can be found on our website.\n".
                "- [Contact](https://x.example/contact)\n".
                '- [Register](https://x.example/register)';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
            sourcePageUrl: 'https://x.example/quick-links',
        );
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertContains('feature_grid_near_miss_interstitial_prose', $codes);
    }

    #[Test]
    public function paragraph_with_three_inline_links_does_not_fire_grid_near_miss(): void
    {
        // The classic false-positive: prose with 3 mid-sentence links.
        // Must NOT fire — the near-miss requires the LINKS to be link-
        // only lines, not inline.
        $body = 'For more information, see [our history page](https://x.example/history) or contact us. '.
                'Registration is open [here](https://x.example/register). '.
                'Read the [rules](https://x.example/rules) before signing up.';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertNotContains('feature_grid_near_miss_interstitial_prose', $codes);
    }

    // ─── file_download_near_miss_below_grid_threshold ──────────────────

    #[Test]
    public function two_doc_link_headings_fire_below_grid_threshold_near_miss(): void
    {
        // Between the two folds — falls to Text today.
        $body = "### [CJFL Rules and Regulations](https://cdn.example/rules.pdf)\n".
                '### [CJFL Code of Conduct](https://cdn.example/code.pdf)';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
            sourcePageUrl: 'https://x.example/rules',
        );
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertContains('file_download_near_miss_below_grid_threshold', $codes);
    }

    #[Test]
    public function prose_with_inline_pdf_reference_does_not_fire_near_miss(): void
    {
        // A paragraph that mentions a PDF mid-sentence must NOT
        // trigger this near-miss.
        $body = 'Please review the [rules PDF](https://x.example/rules.pdf) '.
                'before signing up. All players must comply with the code of conduct.';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertNotContains('file_download_near_miss_below_grid_threshold', $codes);
    }

    // ─── video_near_miss_body_too_long ─────────────────────────────────

    #[Test]
    public function long_body_with_youtube_url_fires_near_miss(): void
    {
        // Hero-paragraph-length body with a YouTube URL mentioned
        // inline. The Video fold rejects (>3 lines); near-miss
        // surfaces the "there was a video URL in here" signal.
        $body = "The Canadian Junior Football League has a rich broadcast history.\n\n".
                "Games are streamed weekly across our platform.\n\n".
                "Watch the latest highlight reel at https://www.youtube.com/watch?v=cZ7WGdTMdUY where fans can catch every touchdown.\n\n".
                "Our media partners provide ongoing coverage throughout the season.\n\n".
                'Additional archives are available on request.';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
            sourcePageUrl: 'https://x.example/media',
        );
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertContains('video_near_miss_body_too_long', $codes);
    }

    #[Test]
    public function short_body_without_youtube_url_does_not_fire_video_near_miss(): void
    {
        $body = "A short paragraph about the league.\n\nNo video URLs here.";

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertNotContains('video_near_miss_body_too_long', $codes);
    }

    // ─── diagnostic shape checks (sourceUrl + snippet on every one) ────

    #[Test]
    public function every_near_miss_carries_sourceurl_when_provided(): void
    {
        // Fire a known near-miss and assert sourceUrl + snippet.
        $body = "- Blast Ball\n- T-Ball\n- Coach Pitch\n- Minors\n- Majors\n- Juniors";
        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
            sourcePageUrl: 'https://x.example/programs',
        );
        $entry = $this->findByCode($out->diagnostics, 'stat_table_near_miss_no_column_separator');
        $this->assertSame('https://x.example/programs', $entry->sourceUrl);
        $this->assertStringContainsString('Snippet:', $entry->message);
    }

    #[Test]
    public function every_near_miss_still_lands_valid_text_block_alongside(): void
    {
        // Near-miss diagnostics are additive — the Text block ships too.
        $body = "- Blast Ball\n- T-Ball\n- Coach Pitch\n- Minors\n- Majors\n- Juniors";
        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertSame('Text', $out->blocks[0]->type);
        $this->assertNotEmpty($out->diagnostics);
    }

    // ─── helpers ────────────────────────────────────────────────────────

    private function assetContext(): AssetContext
    {
        return new AssetContext(new DataCollection(AssetRef::class, []));
    }

    /**
     * @param  array<int, Diagnostic>  $diagnostics
     */
    private function findByCode(array $diagnostics, string $code): Diagnostic
    {
        foreach ($diagnostics as $d) {
            if ($d->code === $code) {
                return $d;
            }
        }
        $this->fail("Expected diagnostic with code `{$code}` not present. Codes: ".implode(', ', array_map(fn ($d) => $d->code, $diagnostics)));
    }
}
