<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

use App\Data\SiteImport\Envelope;
use App\Data\SiteImport\ValidationIssue;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

// Schema-level (JSON-Schema draft 2020-12) validation of a contract
// Envelope. The load-bearing property is that runtime and tests both
// go through this class — no more validating PHP values in one place
// and JSON strings in another.
//
// Uses opis/json-schema ^2.6 against the same schema file
// ContractSchema loads. Runs on the JSON round-trip via EnvelopeJson
// so the shape opis sees is byte-identical to what ships to
// TeamLinkt: root/zones as JSON `{}`, assets[].url required, no
// `diagnostics` extra key at the envelope, block prop enum/type
// rules from `$defs/componentData.allOf`, etc.
//
// This class replaces the ad-hoc `!== []` PHP-value checks that
// used to live in ContractPayloadEmitter::validateEnvelope. The
// per-block checks that are PHP-side only (duplicate block id
// within page, tl-asset:<ref> / assets[] reconciliation, chrome-
// block rejection, server-owned prop authored) STAY where they
// are — opis can't express them without extension keywords we
// haven't wired.
final class ContractPayloadValidator
{
    private readonly Validator $validator;

    private readonly object $schemaObject;

    public function __construct(
        private readonly ContractSchema $schema,
    ) {
        $this->validator = new Validator;
        // Decode via EnvelopeJson to guarantee an object-shaped
        // schema (opis distinguishes `object` from `array` by PHP
        // type, so `JSON_OBJECT_AS_ARRAY` must be off).
        $decoded = EnvelopeJson::decodeObject($schema->rawJson());
        if (! is_object($decoded)) {
            throw new \RuntimeException(
                'Contract schema did not decode as JSON object; got '
                .get_debug_type($decoded).' from '.$schema->path()
            );
        }
        $this->schemaObject = $decoded;
    }

    /**
     * Validate an Envelope against the loaded schema. Round-trips
     * through EnvelopeJson so what opis sees is byte-identical to
     * what will ship.
     *
     * @return array<int, ValidationIssue>
     */
    public function validate(Envelope $envelope): array
    {
        $json = EnvelopeJson::encode($envelope, pretty: false);
        $data = EnvelopeJson::decodeObject($json);

        $result = $this->validator->validate($data, $this->schemaObject);
        if ($result->isValid()) {
            return [];
        }
        $error = $result->error();
        if ($error === null) {
            return [];
        }

        $formatter = new ErrorFormatter;
        $issues = [];
        foreach ($formatter->format($error, multiple: true) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $keyword = is_string($entry['keyword'] ?? null) ? $entry['keyword'] : 'schema';
            $path = is_string($entry['instanceLocation'] ?? null) ? $entry['instanceLocation'] : '';
            $errorMessages = $entry['error'] ?? '';
            $message = self::formatMessage($errorMessages);
            $issues[] = new ValidationIssue(
                severity: 'error',
                code: 'schema_'.$keyword,
                message: $message,
                path: $path === '' ? '(root)' : $path,
            );
        }

        return $issues;
    }

    private static function formatMessage(mixed $raw): string
    {
        if (is_string($raw)) {
            return $raw;
        }
        if (is_array($raw)) {
            $parts = [];
            foreach ($raw as $entry) {
                if (is_string($entry)) {
                    $parts[] = $entry;
                }
            }

            return $parts === [] ? 'schema violation' : implode('; ', $parts);
        }

        return 'schema violation';
    }
}
