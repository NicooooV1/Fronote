<?php
declare(strict_types=1);

namespace Modules\TableauDeBord\Events;

class ClasseUpdated
{
    public function __construct(
        public readonly int $classeId,
        public readonly array $data,
    ) {}
}
