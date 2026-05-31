<?php
declare(strict_types=1);

namespace Modules\Absences\Events;

class RetardDeleted
{
    public function __construct(
        public readonly int $retardId,
    ) {}
}
