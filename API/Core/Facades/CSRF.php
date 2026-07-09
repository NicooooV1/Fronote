<?php
declare(strict_types=1);
namespace API\Core\Facades;

use API\Core\Facade;

/**
 * Facade CSRF
 */
class CSRF extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'csrf';
    }
}
