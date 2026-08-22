<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\Services\Extract\BrandExtractor;
use App\Services\Extract\LogoPaletteExtractor;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

// Load-bearing binding: BrandExtractor MUST get a LogoPaletteExtractor
// injected on the live path. Without this binding, Laravel's autowiring
// passes null for the `?LogoPaletteExtractor = null` param (default
// wins), Brand.palette stays empty on every live conversion, and
// SiteSettingsEmitter silently falls back to the LLM's palette guess —
// the exact silent-loss surface we're closing.
//
// See: AppServiceProvider::register() BrandExtractor singleton.
final class BrandExtractorBindingTest extends TestCase
{
    #[Test]
    public function container_injects_logo_palette_extractor_into_brand_extractor(): void
    {
        $brand = $this->app->make(BrandExtractor::class);

        $rc = new ReflectionClass($brand);
        $prop = $rc->getProperty('paletteExtractor');
        $value = $prop->getValue($brand);

        $this->assertInstanceOf(
            LogoPaletteExtractor::class,
            $value,
            'BrandExtractor.paletteExtractor must be a LogoPaletteExtractor instance; a null here means the '
            .'live palette measurement is disabled and SiteSettingsEmitter will silently fall back to the '
            .'LLM guess.',
        );
    }
}
