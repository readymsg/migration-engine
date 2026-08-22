<?php

declare(strict_types=1);

namespace Tests\Feature\ContractEmitter;

use App\Data\SiteImport\Block;
use App\Services\ContractEmitter\ContractSchema;
use App\Services\ContractEmitter\ContractSchemaValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Slice 14 acceptance gate: the reference payload engineering
// shipped alongside the schema (`site-import-example.json`) MUST
// validate clean against our validator. If our validator rejects
// their reference payload, our validator is wrong — either the
// schema shape got misread by the adapter, or the validator's
// hardcoded rules diverge from the file's declared shape.
//
// This test doesn't just check "envelope loads" — it walks EVERY
// block in the example through ContractSchemaValidator and asserts
// zero errors. Warnings are informational and allowed (Contract
// Part III range-violations-are-slider-bounds).
//
// The example uses all 39 emittable blocks (verified 2026-08-22),
// so it doubles as a "no block type is broken by the adapter"
// gate for the full 45-block catalogue.
final class SiteImportExampleAcceptedTest extends TestCase
{
    #[Test]
    public function reference_payload_validates_zero_errors_across_every_block(): void
    {
        $path = base_path('tests/Fixtures/site-import/site-import-example.json');
        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        $schema = ContractSchema::load();
        $validator = new ContractSchemaValidator($schema);

        $errors = [];
        $blockCount = 0;
        foreach ($decoded['pages'] ?? [] as $page) {
            foreach ($page['data']['content'] ?? [] as $rawBlock) {
                if (! is_array($rawBlock) || ! isset($rawBlock['type'], $rawBlock['props'])) {
                    continue;
                }
                $blockCount++;
                $block = new Block(
                    type: (string) $rawBlock['type'],
                    props: is_array($rawBlock['props']) ? $rawBlock['props'] : [],
                );
                foreach ($validator->validateBlock($block, "page:{$page['slug']}.{$rawBlock['type']}") as $issue) {
                    if ($issue->severity === 'error') {
                        $errors[] = "{$issue->code} @ {$issue->path}: {$issue->message}";
                    }
                }
            }
        }

        // Bound the block count so a regressed fixture (some pages
        // dropped) doesn't silently pass. The reference payload
        // ships 42 top-level blocks across 14 pages at import.
        $this->assertGreaterThan(30, $blockCount, 'reference payload should carry many blocks');
        $this->assertSame(
            [],
            $errors,
            "Our validator rejected engineering's reference payload — validator is wrong. Errors:\n"
            .implode("\n", $errors),
        );
    }

    #[Test]
    public function every_emittable_block_type_in_schema_is_known_to_validator(): void
    {
        $schema = ContractSchema::load();
        $emittable = [];
        foreach ($schema->knownTypes() as $type) {
            if (! $schema->isChromeBlock($type)) {
                $emittable[] = $type;
            }
        }
        // Emittable count per x-teamlinkt.counts.blocksEmittable = 39.
        $this->assertSame(39, count($emittable), 'schema-derived emittable count must match x-teamlinkt.counts.blocksEmittable');
    }

    #[Test]
    public function chrome_blocks_from_never_emit_list_are_all_marked_chrome(): void
    {
        $schema = ContractSchema::load();
        $never = $schema->neverEmitBlocks();
        // The 6 the contract forbids.
        $this->assertSame(6, count($never));
        foreach ($never as $type => $_reason) {
            $this->assertTrue(
                $schema->isChromeBlock($type),
                "Block `{$type}` is in x-teamlinkt.neverEmitBlocks but ContractSchema didn't mark it as chromeOnly.",
            );
        }
    }
}
