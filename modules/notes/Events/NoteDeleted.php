<?php
declare(strict_types=1);

namespace Modules\Notes\Events;

class NoteDeleted
{
    public function __construct(
        public readonly int $noteId,
    ) {}
}
