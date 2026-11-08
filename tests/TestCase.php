<?php

declare(strict_types=1);

namespace CodingAgency\SpamStrike\Tests;

use CodingAgency\SpamStrike\SpamStrikeServiceProvider;
use CodingAgency\SpamStrike\Support\FormToken;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase as Testbench;

abstract class TestCase extends Testbench
{
    /**
     * Token ages are whole seconds: a second passing mid-test must not change them.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond();
    }

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SpamStrikeServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('app.url', 'https://shop.example');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('mail.default', 'array');
        $app['config']->set('spam-strike.reports.enabled', true);
        $app['config']->set('spam-strike.reports.from', ['address' => 'contact@shop.example', 'name' => 'Shop']);
        $app['config']->set('spam-strike.reports.cc', ['owner@shop.example']);
        $app['config']->set('spam-strike.noscript.email', 'contact@shop.example');
        $app['config']->set('view.paths', [__DIR__.'/fixtures']);
    }

    /**
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        Route::post('/contact', fn () => response()->json([
            'sent' => true,
            'seconds' => request()->attributes->get('spam-strike.seconds'),
            'details' => request()->attributes->get('spam-strike.details'),
        ]))->middleware('spam-strike');

        Route::view('/form', 'form');
    }

    protected function token(int $secondsAgo = 10): string
    {
        $this->travel(-$secondsAgo)->seconds();
        $token = FormToken::issue();
        $this->travel($secondsAgo)->seconds();

        return $token;
    }
}
