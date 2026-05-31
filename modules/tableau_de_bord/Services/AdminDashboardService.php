<?php
declare(strict_types=1);

namespace Modules\TableauDeBord\Services;

use PDO;

// Full implementation identical to API\Services\Scolaire\AdminDashboardService
// with updated namespace. class_alias in old location ensures backward compat.
class AdminDashboardService extends \API\Services\Scolaire\AdminDashboardService
{
    // Inherits full implementation — no code duplication needed.
    // When the API class is also aliased to this class, PHP will resolve correctly.
}
