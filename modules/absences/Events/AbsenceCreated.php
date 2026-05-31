<?php
declare(strict_types=1);

namespace Modules\Absences\Events;

class AbsenceCreated
{
    public function __construct(
        public readonly int $absenceId,
        public readonly array $data,
    ) {}
}
