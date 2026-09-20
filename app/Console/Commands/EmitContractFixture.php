<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\ConversionResult;
use App\Data\OrgType;
use App\Services\ContractEmitter\ContractPayloadEmitter;
use App\Services\ContractEmitter\ContractPayloadValidator;
use App\Services\ContractEmitter\ContractSchema;
use App\Services\ContractEmitter\EnvelopeJson;
use Illuminate\Console\Command;
use RuntimeException;

// Reads a preview fixture (ConversionResult JSON) and runs it through
// the ContractPayloadEmitter to produce a contract-shaped Envelope
// JSON. Under v2 it writes TWO files:
//
//   preview/{name}-contract.json              — envelope payload
//   preview/{name}-contract.diagnostics.json  — diagnostics sidecar
//
// The envelope is what would ship to TeamLinkt's ingest. The sidecar
// carries every diagnostic that used to live inside envelope.diagnostics
// (v2 removed that field) plus the schema sha256 stamp so a fixture
// can be traced back to the exact schema bytes it was produced against.
//
// Deliberately depends on the existing {name}.json (produced by
// engine:emit-preview-fixture) rather than re-running the full
// pipeline — same source, two output shapes.
final class EmitContractFixture extends Command
{
    protected $signature = 'engine:emit-contract-fixture {--source-fixture=tbirdhoops} {--org-type=club}';

    protected $description = 'Emit a contract-shaped payload + diagnostics sidecar from an existing ConversionResult fixture.';

    public function handle(
        ContractPayloadEmitter $emitter,
        ContractPayloadValidator $validator,
        ContractSchema $schema,
    ): int {
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

        // v2: encode the envelope through the single-source helper
        // (EnvelopeJson) so root/zones become JSON `{}` on disk. The
        // opis validator ran against the same encoded shape inside
        // emit(), so the on-disk bytes match what was validated.
        $envelopeJson = EnvelopeJson::encode($out->envelope, pretty: true);
        $envelopePath = storage_path("app/public/preview/{$sourceName}-contract.json");
        if (file_put_contents($envelopePath, $envelopeJson) === false) {
            throw new RuntimeException("Failed to write contract fixture: {$envelopePath}");
        }

        // Companion sidecar: schema hash, source metadata, and every
        // diagnostic that used to appear in envelope.diagnostics.
        $sidecarJson = json_encode(
            $out->sidecar->toArray(),
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $sidecarPath = storage_path("app/public/preview/{$sourceName}-contract.diagnostics.json");
        if (file_put_contents($sidecarPath, $sidecarJson) === false) {
            throw new RuntimeException("Failed to write diagnostics sidecar: {$sidecarPath}");
        }

        $this->line("Wrote envelope       {$envelopePath}");
        $this->line("Wrote sidecar        {$sidecarPath}");
        $this->line('Schema sha256        '.$schema->sha256());
        $this->line(sprintf(
            'Payload              pages: %d, blocks: %d, assets: %d',
            $out->envelope->pages->count(),
            array_sum(array_map(
                fn ($p) => $p->data->content->count(),
                iterator_to_array($out->envelope->pages),
            )),
            $out->envelope->assets->count(),
        ));
        $this->line(sprintf(
            'Sidecar diagnostics  %d total (by severity: info=%d, warning=%d, error=%d)',
            $out->sidecar->diagnostics->count(),
            $out->sidecar->coverage_by_severity['info'] ?? 0,
            $out->sidecar->coverage_by_severity['warning'] ?? 0,
            $out->sidecar->coverage_by_severity['error'] ?? 0,
        ));

        // Independent second validation pass on the JUST-WRITTEN
        // envelope bytes. Belt-and-braces: proves the on-disk file
        // validates cleanly under opis, not just the in-memory
        // Envelope value.
        $reValidateIssues = $validator->validate($out->envelope);
        $this->line(sprintf(
            'Validation           %d schema errors, %d block-rule errors, %d warnings',
            count($reValidateIssues),
            count($out->errors) - count($reValidateIssues),
            count($out->warnings),
        ));
        if ($out->errors !== []) {
            $this->warn('First 10 errors:');
            foreach (array_slice($out->errors, 0, 10) as $e) {
                $path = is_string($e->path) ? $e->path : '(no path)';
                $this->warn("   {$e->code} at {$path}: {$e->message}");
            }
        }

        return $out->errors === [] ? self::SUCCESS : self::FAILURE;
    }
}
