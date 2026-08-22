<?php

declare(strict_types=1);

namespace App\Data\SiteImport;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

// One validation finding from ContractSchemaValidator. Populated
// during payload construction; the emitter fails loud if ANY error
// remains in the output. Warnings pass through (e.g. numeric range
// hints — Contract Part III notes ranges are editor slider bounds,
// not saved-value validation).
//
// severity taxonomy is deliberately narrower than Diagnostic's:
//   error   — contract rule violation; payload MUST be repaired or
//             emission MUST fail
//   warning — soft signal (e.g. numeric outside slider range) that
//             is preserved in diagnostics[] but doesn't block emit
final class ValidationIssue extends Data
{
    public function __construct(
        public string $severity,
        public string $code,
        public string $message,
        // Dotted path locating the issue in the envelope, e.g.
        // "pages[0].data.content[3].props.imageUrl" — echoes
        // BlockValidator's path convention from our old Assembler.
        public Optional|string $path = new Optional,
    ) {}
}
