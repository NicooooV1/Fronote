<?php
declare(strict_types=1);

namespace API\Security;

/**
 * WildcardGrants — résolution UNIQUE des wildcards de permissions ('*' et 'préfixe.*').
 *
 * Mutualise les quatre implémentations historiquement dupliquées (RoleCatalog, Authorization,
 * TenantRoleCatalog, PlatformRoleCatalog) : une seule logique = plus de divergence possible entre
 * les mondes d'autorisation (thème 2 de la revue de design).
 *
 * Sémantique (préfixe multi-niveaux, sur-ensemble strict du domaine simple) :
 *   - '*'            → accorde tout ;
 *   - 'a.b.c'        → accorde exactement 'a.b.c' ;
 *   - 'a.b.*'        → accorde toute clé sous 'a.b.' ;
 *   - pour 'a.b.c', on teste 'a.b.*' PUIS 'a.*'.
 * Pour des clés à 2 niveaux ('domaine.action') avec des wildcards 'domaine.*', ce résultat est
 * identique à l'ancienne résolution « premier segment seulement ».
 */
final class WildcardGrants
{
    /**
     * Un ensemble de grants (avec wildcards) accorde-t-il la permission demandée ?
     * @param string[] $grants
     */
    public static function granted(array $grants, string $permission): bool
    {
        if (in_array('*', $grants, true) || in_array($permission, $grants, true)) {
            return true;
        }
        $parts = explode('.', $permission);
        for ($i = count($parts) - 1; $i >= 1; $i--) {
            if (in_array(implode('.', array_slice($parts, 0, $i)) . '.*', $grants, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Développe des grants (avec wildcards) en liste concrète de permissions, contre un univers
     * de clés connues. '*' → tout l'univers ; 'préfixe.*' → toutes les clés sous 'préfixe.'.
     * @param string[] $grants
     * @param string[] $universe clés de permissions valides
     * @return string[] clés effectives (uniques)
     */
    public static function expand(array $grants, array $universe): array
    {
        if (in_array('*', $grants, true)) {
            return array_values($universe);
        }
        $uni = array_flip($universe);
        $out = [];
        foreach ($grants as $g) {
            if (str_ends_with($g, '.*')) {
                $prefix = substr($g, 0, -1); // conserve le point final → 'préfixe.'
                foreach ($universe as $pk) {
                    if (str_starts_with($pk, $prefix)) {
                        $out[$pk] = true;
                    }
                }
            } elseif (isset($uni[$g])) {
                $out[$g] = true;
            }
        }
        return array_keys($out);
    }
}
