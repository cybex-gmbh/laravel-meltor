<?php

namespace Meltor;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Meltor\Skeleton\SkeletonClass
 */
class MeltorFacade extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'meltor';
    }
}
