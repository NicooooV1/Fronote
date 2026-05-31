<?php
declare(strict_types=1);

namespace Modules\Devoirs\Events;

class DevoirDeleted
{
    public function __construct(
        public readonly int $devoirId,
    ) {}
}
