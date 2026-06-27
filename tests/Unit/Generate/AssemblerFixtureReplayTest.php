<?php

declare(strict_types=1);

namespace Tests\Unit\Generate;

use App\Data\AssemblyStatus;
use App\Data\BlockFillResult;
use App\Data\PuckOutput;
use App\Services\Generate\Assembler;
use App\Services\Generate\BlockCoercer;
use App\Services\Schema\DefaultPuckComponentSchema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Asserts the durable tbirdhoops BlockFillResult fixture
// (`tests/Fixtures/blockfill/tbirdhoops.json`) assembles cleanly under
// the current Assembler. If real Sonnet output ever lands a shape the
// assembler can't handle without coercion, this test goes red and the
// reviewer can decide: tighten the block-fill prompt OR refine the
// coercer. Either way, the fixture stops being silent.
//
// Regenerate the fixture via `engine:capture-tbirdhoops-block-fill`
// (costs real Opus + Sonnet credits; see the command's docblock).
final class AssemblerFixtureReplayTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../../Fixtures/blockfill/tbirdhoops.json';

    #[Test]
    public function tbirdhoops_fixture_assembles_cleanly(): void
    {
        $this->assertFileExists(self::FIXTURE, 'fixture missing — re-run engine:capture-tbirdhoops-block-fill');
        $raw = file_get_contents(self::FIXTURE);
        $this->assertIsString($raw);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);

        $blockFill = BlockFillResult::from($decoded);
        $this->assertSame(7, $blockFill->pages->count(), 'fixture should carry 7 real-Sonnet FilledPages');

        $schema = new DefaultPuckComponentSchema;
        $assembler = new Assembler(new BlockCoercer($schema));
        $result = $assembler->run($blockFill);

        $this->assertSame(AssemblyStatus::Complete, $result->status);
        $this->assertSame(7, $result->pages->count());
        $this->assertSame(0, $result->failures->count());
        $this->assertSame([], $result->block_issues_by_slug, 'no substitutions or drops on the real Sonnet output');

        // Spot-check the Board page: the prior dump confirmed 10 board
        // members in a Columns-of-Cards. The assembler must preserve
        // the nested Card props VERBATIM through Columns.columns[]
        // .children — both the title (name) AND the body (role) — so a
        // future recursion regression that blanks/swaps a nested prop
        // can't hide behind a count-only check.
        $bySlug = [];
        /** @var array<int, PuckOutput> $pages */
        $pages = $result->pages->items();
        foreach ($pages as $page) {
            $bySlug[$page->page_slug] = $page;
        }
        $this->assertArrayHasKey('page-7188117', $bySlug, 'Board page (page-7188117) must be in the result');
        $board = $bySlug['page-7188117'];

        $titleToBody = $this->collectNestedCardTitleBodyPairs($board);

        // Assert specific (name, role) pairs are paired together —
        // ordering doesn't matter, but a role attached to the WRONG
        // name (or to no name) would catch a children-array shuffle.
        $expectedPairs = [
            'Scott Whitenack' => 'President',
            'Janet Habedank' => 'Treasurer',
            'Eric Ziegler' => 'Flight Director 3rd - 6th Girls',
            'Kathy Steen' => 'League Scheduler & Database Administrator',
        ];
        foreach ($expectedPairs as $name => $role) {
            $this->assertArrayHasKey(
                $name,
                $titleToBody,
                "Board page must carry Card.title='{$name}' through the Columns→Card recursion"
            );
            $this->assertSame(
                $role,
                $titleToBody[$name],
                "Board page must carry Card.body='{$role}' paired with Card.title='{$name}'"
            );
        }

        // Structural backstop: every nested Card on the page must have
        // BOTH a non-empty title and body. A regression that blanks a
        // prop while keeping the Card shape would slip past the named
        // assertions above for ANY card not explicitly named — this
        // assertion catches it across all of them.
        foreach ($titleToBody as $title => $body) {
            $this->assertNotSame('', trim($title), 'nested Card.title must be non-empty');
            $this->assertNotSame('', trim($body), "nested Card title='{$title}' lost its body during assembly");
        }
    }

    /**
     * @return array<string, string> title (name) → body (role)
     */
    private function collectNestedCardTitleBodyPairs(PuckOutput $page): array
    {
        /** @var array<string, string> $pairs */
        $pairs = [];
        foreach ($page->content as $block) {
            if (($block['type'] ?? null) !== 'Columns') {
                continue;
            }
            $columns = $block['props']['columns'] ?? [];
            if (! is_array($columns)) {
                continue;
            }
            foreach ($columns as $column) {
                $children = is_array($column) ? ($column['children'] ?? []) : [];
                if (! is_array($children)) {
                    continue;
                }
                foreach ($children as $child) {
                    if (! is_array($child) || ($child['type'] ?? null) !== 'Card') {
                        continue;
                    }
                    $title = $child['props']['title'] ?? null;
                    $body = $child['props']['body'] ?? null;
                    if (! is_string($title) || $title === '') {
                        continue;
                    }
                    $pairs[$title] = is_string($body) ? $body : '';
                }
            }
        }

        return $pairs;
    }
}
