<?php
declare(strict_types=1);

namespace Modules\Agenda\Events;

class EvenementUpdated
{
    public function __construct(
        public readonly int $evenementId,
        public readonly array $data,
    ) {}
}
