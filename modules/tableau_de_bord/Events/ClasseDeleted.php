<?php
declare(strict_types=1);

namespace Modules\TableauDeBord\Events;

class ClasseDeleted
{
    public function __construct(
        public readonly int $classeId,
    ) {}
}
