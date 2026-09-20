<?php

namespace Tests;

use App\Services\Assets\AssetPublisher;
use App\Services\Assets\FakePublicAssetHost;
use App\Services\Assets\SvgRasterizer;
use App\Services\Assets\SyntheticAssetPublisher;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // v2 asset hosting: tests use SyntheticAssetPublisher so
        // AssetLedger doesn't try to fetch bytes over the network
        // when a test constructs URLs like `https://cdn.example.com/…`.
        // Production and fixture-emission paths keep the real
        // (fetching) AssetPublisher via AppServiceProvider.
        //
        // The SyntheticAssetPublisher's SVG branch runs REAL librsvg
        // (via SvgRasterizer, resolved by the container's config), so
        // SvgRasterizationTest exercises the same shell-out prod does.
        $this->app->singleton(AssetPublisher::class, function ($app) {
            return new SyntheticAssetPublisher(
                new FakePublicAssetHost,
                $app->make(SvgRasterizer::class),
            );
        });
    }
}
