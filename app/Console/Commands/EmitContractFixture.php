<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\ConversionResult;
use App\Data\OrgType;
use App\Services\ContractEmitter\ContractPayloadEmitter;
use Illuminate\Console\Command;
use RuntimeException;

// Reads the tbirdhoops preview fixture (ConversionResult JSON) and
// runs it through the ContractPayloadEmitter to produce a contract-
// shaped Envelope JSON. Output lands at:
//   storage/app/public/preview/tbirdhoops-contract.json
//
// Consumed by the contract-preview React bundle. This is the M1
// milestone artifact — a valid, previewable payload against the
// Site Import Contract v1.
//
// Deliberately depends on the existing tbirdhoops.json (produced by
// engine:emit-preview-fixture) rather than re-running the full
// pipeline — same source, two output shapes. The Slice-19 block-
// fill re-prompt would collapse the two into one output, but for
// M1 the contract emitter is a downstream translator.
final class EmitContractFixture extends Command
{
    protected $signature = 'engine:emit-contract-fixture {--source-fixture=tbirdhoops} {--org-type=club}';

    protected $description = 'Emit a contract-shaped payload from an existing ConversionResult fixture.';

    public function handle(ContractPayloadEmitter $emitter): int
    {
        $sourceName = (string) $this->option('source-fixture');
        $orgTypeValue = (string) $this->option('org-type');
        $orgType = OrgType::tryFrom($orgTypeValue);
        if ($orgType === null) {
            $this->error("Unknown --org-type `{$orgTypeValue}`. Valid: club, association, league, high_school, civic, multi_location.");

            return self::FAILURE;
        }

        $sourcePath = storage_path("app/public/preview/{$sourceName}.json");
        if (! is_file($sourcePath)) {
            $this->error("Source fixture not found: {$sourcePath}. Run engine:emit-preview-fixture first.");

            return self::FAILURE;
        }
        $raw = json_decode((string) file_get_contents($sourcePath), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($raw)) {
            throw new RuntimeException("Source fixture is not a JSON object: {$sourcePath}");
        }
        $result = ConversionResult::from($raw);

        $out = $emitter->emit($result, $orgType);
        $envelope = $out->envelope->toArray();

        // Attach the validation verdict alongside the envelope for
        // preview-side transparency. NOT part of the contract shape —
        // the fixture is a DEBUG artifact, not something we'd ship.
        $sidecarPath = storage_path("app/public/preview/{$sourceName}-contract.json");
        $written = file_put_contents($sidecarPath, json_encode($envelope, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        if ($written === false) {
            throw new RuntimeException("Failed to write contract fixture: {$sidecarPath}");
        }

        $this->line("Wrote contract fixture to {$sidecarPath}");
        $this->line(sprintf(
            ' pages: %d, blocks: %d, assets: %d, diagnostics: %d',
            count($envelope['pages']),
            array_sum(array_map(fn ($p) => count($p['data']['content']), $envelope['pages'])),
            count($envelope['assets']),
            count($envelope['diagnostics']),
        ));
        $this->line(sprintf(' validation: %d errors, %d warnings', count($out->errors), count($out->warnings)));
        if ($out->errors !== []) {
            $this->warn('First 5 errors:');
            foreach (array_slice($out->errors, 0, 5) as $e) {
                $path = is_string($e->path) ? $e->path : '(no path)';
                $this->warn("   {$e->code} at {$path}: {$e->message}");
            }
        }
        $this->line("Browse: http://127.0.0.1:8000/preview-contract/{$sourceName}");

        return self::SUCCESS;
    }
}
