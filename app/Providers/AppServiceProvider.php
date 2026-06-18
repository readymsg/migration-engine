<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Product\ProductClient;
use App\Services\Product\StubProductClient;
use App\Services\Schema\ComponentSchema;
use App\Services\Schema\DefaultPuckComponentSchema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ComponentSchema::class, DefaultPuckComponentSchema::class);
        $this->app->singleton(ProductClient::class, StubProductClient::class);
    }

    public function boot(): void
    {
        //
    }
}
