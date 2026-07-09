<?php
declare(strict_types=1);
// Backward-compat shim — implementation moved to Modules\Notes\Services\NoteService (CDC Phase 3)
class_alias(\Modules\Notes\Services\NoteService::class, 'API\Services\Scolaire\NoteService');
