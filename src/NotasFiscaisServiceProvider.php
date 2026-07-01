<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais;

use Illuminate\Support\ServiceProvider;

class NotasFiscaisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/notas-fiscais.php', 'notas-fiscais');

        $this->app->singleton(NotaFiscalManager::class, fn ($app) => new NotaFiscalManager($app));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/notas-fiscais.php' => $this->app->configPath('notas-fiscais.php'),
            ], 'notas-fiscais-config');
        }

        // Playground de validação visual — carregado apenas quando habilitado.
        if ((bool) $this->app['config']->get('notas-fiscais.demo')) {
            $this->loadViewsFrom(__DIR__.'/../resources/views', 'notas-fiscais');
            $this->loadRoutesFrom(__DIR__.'/../routes/demo.php');
        }
    }
}
