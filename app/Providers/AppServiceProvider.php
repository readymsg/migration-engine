<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Extract\AssetUploader;
use App\Services\Extract\Extractor;
use App\Services\Extract\FirecrawlClient;
use App\Services\Extract\HtmlFetcher;
use App\Services\Extract\HttpFirecrawlClient;
use App\Services\Extract\HttpHtmlFetcher;
use App\Services\Extract\HttpRootNavFetcher;
use App\Services\Extract\RootNavFetcher;
use App\Services\Extract\S3AssetUploader;
use App\Services\Extract\SportNginExtractor;
use App\Services\Generate\AnthropicBlockFillAgent;
use App\Services\Generate\AnthropicIrPassAgent;
use App\Services\Generate\Assembler;
use App\Services\Generate\BlockCoercer;
use App\Services\Generate\BlockFill;
use App\Services\Generate\BlockFillAgent;
use App\Services\Generate\BlockFillContextStore;
use App\Services\Generate\BlockFillResultStore;
use App\Services\Generate\BlockValidator;
use App\Services\Generate\CacheBlockFillContextStore;
use App\Services\Generate\CacheBlockFillResultStore;
use App\Services\Generate\ContentLoader;
use App\Services\Generate\IrPass;
use App\Services\Generate\IrPassAgent;
use App\Services\Generate\PlatformBlockRenderer;
use App\Services\Plan\AnthropicClassifierAgent;
use App\Services\Plan\ClassifierAgent;
use App\Services\Plan\Planner;
use App\Services\Plan\RootNavPlanner;
use App\Services\Plan\SePlatformContentDetector;
use App\Services\Product\ProductClient;
use App\Services\Product\StubProductClient;
use App\Services\Schema\ComponentSchema;
use App\Services\Schema\DefaultPuckComponentSchema;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ComponentSchema::class, DefaultPuckComponentSchema::class);
        $this->app->singleton(ProductClient::class, StubProductClient::class);

        $this->app->singleton(HtmlFetcher::class, HttpHtmlFetcher::class);
        $this->app->singleton(RootNavFetcher::class, HttpRootNavFetcher::class);
        $this->app->singleton(FirecrawlClient::class, function (Application $app): FirecrawlClient {
            return new HttpFirecrawlClient(
                apiKey: (string) config('services.firecrawl.api_key', ''),
                baseUrl: (string) config('services.firecrawl.url', 'https://api.firecrawl.dev/v2'),
            );
        });
        $this->app->singleton(AssetUploader::class, function (Application $app): AssetUploader {
            return new S3AssetUploader(
                disk: (string) config('services.scrapes.disk', 's3'),
            );
        });
        $this->app->singleton(Extractor::class, SportNginExtractor::class);

        $this->app->singleton(ClassifierAgent::class, AnthropicClassifierAgent::class);
        $this->app->singleton(SePlatformContentDetector::class);
        $this->app->singleton(Planner::class, RootNavPlanner::class);

        $this->app->singleton(IrPassAgent::class, AnthropicIrPassAgent::class);
        $this->app->singleton(ContentLoader::class, function (Application $app): ContentLoader {
            return new ContentLoader(
                disk: (string) config('services.scrapes.disk', 's3'),
            );
        });
        $this->app->singleton(IrPass::class);

        $this->app->singleton(BlockFillAgent::class, AnthropicBlockFillAgent::class);
        $this->app->singleton(BlockFillContextStore::class, CacheBlockFillContextStore::class);
        $this->app->singleton(BlockFillResultStore::class, CacheBlockFillResultStore::class);
        $this->app->singleton(BlockFill::class);

        // GENERATE stage 3 slice 2d — deterministic assembler. Singletons
        // so DI gives every caller the same schema-bound instance and
        // the artisan replay command resolves cleanly.
        $this->app->singleton(BlockValidator::class);
        $this->app->singleton(BlockCoercer::class);
        $this->app->singleton(Assembler::class);

        // GENERATE stage 3 slice 2e — deterministic platform-block renderer.
        // Same posture as the assembler: schema-bound singleton so 2f's
        // draft-landing orchestrator gets one instance via DI.
        $this->app->singleton(PlatformBlockRenderer::class);
    }

    public function boot(): void
    {
        //
    }
}
