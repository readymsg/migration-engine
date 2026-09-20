<?php

namespace Tests;

use App\Services\Assets\AssetPublisher;
use App\Services\Assets\FakePublicAssetHost;
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
        $this->app->singleton(AssetPublisher::class, function ($app) {
            return new SyntheticAssetPublisher(new FakePublicAssetHost);
        });
    }
}
