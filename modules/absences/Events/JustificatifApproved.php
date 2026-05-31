<?php
declare(strict_types=1);

namespace Modules\Absences\Events;

class JustificatifApproved
{
    public function __construct(
        public readonly int $justificatifId,
        public readonly int $adminId,
        public readonly string $comment,
    ) {}
}
