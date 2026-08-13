<?php

declare(strict_types=1);

namespace API\Services;

use PDO;

/**
 * Classe de base des services applicatifs qui reçoivent une connexion PDO à la construction.
 *
 * Factorise le couple `protected PDO $pdo` + constructeur injectant PDO, recopié à l'identique
 * dans une centaine de services (modules et cœur). Les sous-classes qui n'ont besoin que de PDO
 * héritent tel quel ; celles qui ont d'autres dépendances gardent leur constructeur et appellent
 * `parent::__construct($pdo)`.
 *
 * Note portée : la plupart de ces services sont cloisonnés par établissement au niveau des
 * requêtes (via \API\Core\EstablishmentContext::id()). Cette base ne fait QUE porter la connexion ;
 * elle n'impose pas de scope pour ne pas modifier le comportement existant.
 */
abstract class AbstractTenantService
{
    protected PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
}
