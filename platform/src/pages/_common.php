<?php
declare(strict_types=1);
/** Petites requêtes partagées par plusieurs pages. */

function pf_badges(): array
{
    try {
        $open = (int) pf_db()->query("SELECT COUNT(*) FROM tickets WHERE status IN ('nouveau','en_cours')")->fetchColumn();
    } catch (\Throwable $e) { $open = 0; }
    return ['support' => $open];
}

const PF_STATUS = [
    'autorise' => ['Autorisé — en attente d\'installation', 'info'],
    'installe' => ['Installé', 'ok'],
    'suspendu' => ['Suspendu (licence révoquée)', 'crit'],
    'archive'  => ['Archivé', 'muted'],
];

const PF_TICKET_STATUS = [
    'nouveau' => ['Nouveau', 'warn'],
    'en_cours' => ['En cours', 'info'],
    'attente_etablissement' => ['En attente de l\'établissement', 'muted'],
    'resolu' => ['Résolu', 'ok'],
    'ferme' => ['Fermé', 'muted'],
];

/** Parc (CT122) indexé par license_id. */
function pf_park_by_license(): array
{
    $park = Dist::get('/admin/park', 'park');
    $out = [];
    foreach (($park['data']['instances'] ?? []) as $i) {
        $out[(int) ($i['license_id'] ?? 0)][] = $i;
    }
    return $out;
}

function pf_instance_tone(array $i): array
{
    $silence = (int) ($i['silence_seconds'] ?? PHP_INT_MAX);
    if ($silence > 3 * 86400) { return ['Muette depuis ' . intdiv($silence, 86400) . ' j', 'crit']; }
    if (empty($i['healthy'])) { return ['Santé dégradée', 'warn']; }
    return ['En ligne', 'ok'];
}
