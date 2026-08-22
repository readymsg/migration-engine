<?php

declare(strict_types=1);

namespace App\Data\SiteImport;

use Spatie\LaravelData\Data;

// One placed block in a page's content array. Contract's ComponentData
// shape: `{ type, props }`. Site Import Contract Part II "Blocks".
//
// props is deliberately weakly typed here — prop shapes vary widely
// across the 39 emittable block types AND per-block prop validation
// is the ContractSchemaValidator's job (Slice 2). Locking the shape
// down in this DTO would double-source it. What IS invariant:
//   - `props.id` MUST be present and unique within the page. This DTO
//     doesn't enforce that structurally either — the validator does.
//     Every block ships with `props: { id: '...' }` at minimum.
//   - No prop key may start with `resolved` and no `formUuid` — those
//     are server-owned; contract Part II "Do not author these".
//
// Six of the 45 documented block types must NEVER be emitted at all
// (IntakeForm + 5 chrome blocks). The validator refuses them by type.
final class Block extends Data
{
    /**
     * @param  array<string, mixed>  $props  every prop is optional per the contract; a stored `""` means deliberately blank, an absent key means take-default. `null` is never correct — omit the key instead.
     */
    public function __construct(
        public string $type,
        public array $props,
    ) {}
}
