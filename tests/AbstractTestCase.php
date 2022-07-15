<?php

namespace Meltor\Tests;

use Meltor\MeltorServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class AbstractTestCase extends Orchestra
{
    /**
     * Get package providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     *
     * @return array
     */
    protected function getPackageProviders($app): array
    {
        return [
            MeltorServiceProvider::class
        ];
    }
}