<?php
declare(strict_types=1);

namespace Modules\EmploiDuTemps\Events;

class PeriodeDeleted
{
    public function __construct(
        public readonly int $periodeId,
    ) {}
}
