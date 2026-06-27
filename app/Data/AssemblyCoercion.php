<?php

declare(strict_types=1);

namespace App\Data;

// Two-class split for what the assembler did to a block during the one
// repair pass. The test is: did the coercion change WHAT the source said
// (record it) or just HOW it's encoded (silent, no issue recorded)?
//
//   - Substitution: value-changing but block-preserving. The block
//     survives into PuckOutput.content but with a value the LLM did NOT
//     emit — e.g. an empty href coerced to '#', or a select value not in
//     the schema's options coerced to the documented default.
//   - Drop: the block was removed from PuckOutput.content because it
//     could not be validated even after coercion — e.g. a Card missing
//     its required title, or an unknown component_type.
//
// Normalizations (value-preserving: stringy-number→number, h1-h6 case
// fix, whitespace trim, drop unknown prop keys, drop missing-optional
// fields) are applied silently and have NO AssemblyBlockIssue.
enum AssemblyCoercion: string
{
    case Substitution = 'substitution';
    case Drop = 'drop';
}
