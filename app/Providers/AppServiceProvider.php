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
use App\Services\Plan\AnthropicClassifierAgent;
use App\Services\Plan\ClassifierAgent;
use App\Services\Plan\Planner;
use App\Services\Plan\RootNavPlanner;
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
                baseUrl: (string) config('services.firecrawl.url', 'https://api.firecrawl.dev/v1'),
            );
        });
        $this->app->singleton(AssetUploader::class, S3AssetUploader::class);
        $this->app->singleton(Extractor::class, SportNginExtractor::class);

        $this->app->singleton(ClassifierAgent::class, AnthropicClassifierAgent::class);
        $this->app->singleton(Planner::class, RootNavPlanner::class);
    }

    public function boot(): void
    {
        //
    }
}
