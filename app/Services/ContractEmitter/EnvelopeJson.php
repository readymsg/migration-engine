<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

use App\Data\SiteImport\Envelope;
use JsonException;
use RuntimeException;
use stdClass;

// Single source of truth for how a contract Envelope becomes JSON and
// how JSON becomes the shape opis/json-schema validates. Runtime
// (ContractPayloadEmitter) and tests MUST go through this class so
// they can't diverge on serialization semantics.
//
// Two round-trip decisions live here:
//
//  1. PageData.root and PageData.zones are typed `array` on the DTO
//     (so PHP code can call `$page->data->root === []`). spatie's
//     transformer would serialise them as JSON `[]`, but the contract
//     requires JSON `{}` (`$defs/pageData` has `type: object,
//     maxProperties: 0`). This class rewrites both to `stdClass` at
//     encode time — one place, tested by round-trip.
//
//  2. opis/json-schema validates against PHP objects (not associative
//     arrays), because JSON objects and JSON arrays would both look
//     like PHP arrays otherwise. `decodeObject()` returns the decoded
//     payload with `JSON_THROW_ON_ERROR` and objects preserved.
//
// Nothing else in the codebase should call `json_encode`/`json_decode`
// on an Envelope directly.
final class EnvelopeJson
{
    /**
     * Encode an Envelope to the JSON string that ships as the payload.
     * Wraps empty root/zones as JSON `{}` (see class docblock).
     */
    public static function encode(Envelope $envelope, bool $pretty = true): string
    {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        try {
            return json_encode(self::encodeArray($envelope), $flags);
        } catch (JsonException $e) {
            throw new RuntimeException("Failed to encode envelope to JSON: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Envelope → associative array with root/zones normalised.
     *
     * @return array<string, mixed>
     */
    public static function encodeArray(Envelope $envelope): array
    {
        $payload = $envelope->toArray();

        if (! isset($payload['pages']) || ! is_array($payload['pages'])) {
            return $payload;
        }
        foreach ($payload['pages'] as $i => $page) {
            if (! is_array($page) || ! isset($page['data']) || ! is_array($page['data'])) {
                continue;
            }
            $data = $page['data'];
            // root: MUST serialise as JSON object. spatie hands us
            // `[]` (JSON array) for the default empty case. Force
            // `stdClass` so json_encode emits `{}`.
            if (! isset($data['root']) || $data['root'] === [] || ! is_object($data['root'])) {
                $data['root'] = new stdClass;
            }
            if (! isset($data['zones']) || $data['zones'] === [] || ! is_object($data['zones'])) {
                $data['zones'] = new stdClass;
            }
            $payload['pages'][$i]['data'] = $data;
        }

        return $payload;
    }

    /**
     * Decode a payload JSON string into the object shape opis wants
     * (`JSON_OBJECT_AS_ARRAY` OFF — otherwise JSON arrays and JSON
     * objects both become PHP arrays and the schema's `type: object`
     * vs `type: array` distinction is lost).
     */
    public static function decodeObject(string $json): mixed
    {
        try {
            return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Failed to decode envelope JSON: {$e->getMessage()}", 0, $e);
        }
    }
}
