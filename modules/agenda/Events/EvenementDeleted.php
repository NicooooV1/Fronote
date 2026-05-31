<?php
declare(strict_types=1);

namespace Modules\Agenda\Events;

class EvenementDeleted
{
    public function __construct(
        public readonly int $evenementId,
    ) {}
}
