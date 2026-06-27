<?php

declare(strict_types=1);

namespace App\Services\Generate;

// Internal vocabulary for what BlockValidator can flag on a single
// FilledBlock (or a nested child block). Each kind tells BlockCoercer
// what to attempt: type mismatches are normalisable (silent), missing
// requireds and invalid selects are substitutable (recorded), unknown
// components are not recoverable (drop+record).
enum ValidationKind: string
{
    // The block's component_type is not in ComponentSchema. Not
    // recoverable — coercer drops the block.
    case UnknownComponent = 'unknown_component';

    // A required FieldDefinition's value is null / empty string / empty
    // array. Coercer attempts a substitution for known field shapes
    // (empty href → '#') or drops the block when there's no safe
    // substitution (required content text on Hero/Heading/Card/Text).
    case MissingRequired = 'missing_required';

    // The value is non-empty but its type doesn't match the field's
    // declared type (number got string, image got int, object got
    // string, etc.). Coercer attempts type coercion silently when the
    // cast is lossless ('2' → 2, ' h1 ' → 'h1'); otherwise the block
    // is dropped.
    case WrongType = 'wrong_type';

    // The value is a valid string but not in the field's `options`
    // array (select / radio). Coercer substitutes the documented
    // default (first option) and records.
    case InvalidSelectValue = 'invalid_select_value';

    // The block's props bag contains a key that isn't in the schema.
    // Always a silent normalisation — coercer drops the key, no
    // block_issue recorded.
    case UnknownProp = 'unknown_prop';
}
