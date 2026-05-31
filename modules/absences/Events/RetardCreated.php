<?php
declare(strict_types=1);

namespace Modules\Absences\Events;

class RetardCreated
{
    public function __construct(
        public readonly int $retardId,
        public readonly array $data,
    ) {}
}
