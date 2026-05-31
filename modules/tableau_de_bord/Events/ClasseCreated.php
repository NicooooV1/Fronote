<?php
declare(strict_types=1);

namespace Modules\TableauDeBord\Events;

class ClasseCreated
{
    public function __construct(
        public readonly int $classeId,
        public readonly array $data,
    ) {}
}
