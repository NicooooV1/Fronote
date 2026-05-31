<?php
declare(strict_types=1);

namespace Modules\Absences\Events;

class AbsenceDeleted
{
    public function __construct(
        public readonly int $absenceId,
    ) {}
}
