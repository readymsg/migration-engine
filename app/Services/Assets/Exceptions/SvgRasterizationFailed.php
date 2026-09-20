<?php

declare(strict_types=1);

namespace App\Services\Assets\Exceptions;

use RuntimeException;

// Thrown when rsvg-convert exits non-zero, times out, or emits empty
// output. Distinct from `SvgRasterizerUnavailable` (missing binary)
// so the caller can distinguish an infra problem from a per-asset
// failure. Message carries the underlying stderr for the sidecar.
final class SvgRasterizationFailed extends RuntimeException {}
