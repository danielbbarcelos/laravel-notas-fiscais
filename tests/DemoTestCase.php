<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais\Tests;

/** TestCase com o playground (config demo) habilitado. */
abstract class DemoTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('notas-fiscais.demo', true);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }
}
