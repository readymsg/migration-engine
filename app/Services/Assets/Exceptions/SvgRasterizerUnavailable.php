<?php

declare(strict_types=1);

namespace App\Services\Assets\Exceptions;

use RuntimeException;

// Thrown when `rsvg-convert` isn't installed (or is set but not
// executable) at the configured path. Caller (AssetPublisher) catches
// and falls back to the deferred-with-diagnostic path so a missing
// binary is a per-deploy infra problem, not a runtime crash.
final class SvgRasterizerUnavailable extends RuntimeException {}
