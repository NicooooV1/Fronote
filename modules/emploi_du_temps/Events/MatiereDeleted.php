<?php
declare(strict_types=1);

namespace Modules\EmploiDuTemps\Events;

class MatiereDeleted
{
    public function __construct(
        public readonly int $matiereId,
    ) {}
}
