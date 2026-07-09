<?php
declare(strict_types=1);
// Backward-compat shim — implementation moved to Modules\Absences\Jobs\SendAbsenceNotificationJob (CDC Phase 4)
class_alias(\Modules\Absences\Jobs\SendAbsenceNotificationJob::class, 'API\Jobs\SendAbsenceNotificationJob');
