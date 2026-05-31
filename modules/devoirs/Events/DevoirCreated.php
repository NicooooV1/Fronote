<?php
declare(strict_types=1);

namespace Modules\Devoirs\Events;

class DevoirCreated
{
    public function __construct(
        public readonly int $devoirId,
        public readonly array $data,
    ) {}
}
