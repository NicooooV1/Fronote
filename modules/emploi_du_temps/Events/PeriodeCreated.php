<?php
declare(strict_types=1);

namespace Modules\EmploiDuTemps\Events;

class PeriodeCreated
{
    public function __construct(
        public readonly int $periodeId,
        public readonly array $data,
    ) {}
}
