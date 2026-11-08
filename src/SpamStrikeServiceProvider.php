<?php

declare(strict_types=1);

namespace CodingAgency\SpamStrike;

use CodingAgency\SpamStrike\Http\Middleware\ProtectAgainstSpam;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

final class SpamStrikeServiceProvider extends ServiceProvider
{
    /**
     * The script the fields component puts in the page, once.
     */
    public static function script(): string
    {
        return (string) file_get_contents(__DIR__.'/../resources/js/spam-strike.js');
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/spam-strike.php', 'spam-strike');
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('spam-strike', ProtectAgainstSpam::class);

        $this->loadRoutesFrom(__DIR__.'/../routes/spam-strike.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'spam-strike');
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'spam-strike');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/spam-strike.php' => config_path('spam-strike.php')], 'spam-strike-config');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/spam-strike')], 'spam-strike-views');
        }
    }
}
