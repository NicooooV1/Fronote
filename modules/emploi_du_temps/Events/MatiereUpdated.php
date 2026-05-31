<?php
declare(strict_types=1);

namespace Modules\EmploiDuTemps\Events;

class MatiereUpdated
{
    public function __construct(
        public readonly int $matiereId,
        public readonly array $data,
    ) {}
}
