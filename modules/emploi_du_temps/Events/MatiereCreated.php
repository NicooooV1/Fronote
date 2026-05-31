<?php
declare(strict_types=1);

namespace Modules\EmploiDuTemps\Events;

class MatiereCreated
{
    public function __construct(
        public readonly int $matiereId,
        public readonly array $data,
    ) {}
}
