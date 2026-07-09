<?php
declare(strict_types=1);
/**
 * M23 – RGPD — Mentions légales (Art. 12-14 Information)
 * Page statique. Les informations concrètes (éditeur, hébergeur, DPO)
 * appartiennent à l'établissement : elles sont laissées en PLACEHOLDERS
 * entre crochets à compléter par l'administrateur.
 */
$pageTitle = 'Mentions légales';
$activePage = 'mentions_legales';
require_once __DIR__ . '/includes/header.php';
?>

<div class="content-wrapper">
    <div class="content-header">
        <h1><i class="fas fa-gavel"></i> Mentions légales</h1>
    </div>

    <div class="rgpd-info-banner">
        <i class="fas fa-info-circle"></i>
        <p>
            Conformément aux articles 12 à 14 du RGPD et à la législation en vigueur, vous trouverez
            ci-dessous les informations relatives à l'éditeur du service, à son hébergement et au
            responsable de la protection des données.
        </p>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h2>Éditeur du site / Responsable de traitement</h2></div>
        <div class="card-body">
            <ul class="export-data-list">
                <li><i class="fas fa-building text-primary"></i> <strong>Établissement :</strong> [Nom de l'établissement]</li>
                <li><i class="fas fa-map-marker-alt text-primary"></i> <strong>Adresse :</strong> [Adresse postale complète]</li>
                <li><i class="fas fa-phone text-primary"></i> <strong>Téléphone :</strong> [Numéro de téléphone]</li>
                <li><i class="fas fa-envelope text-primary"></i> <strong>Email :</strong> [Email de contact]</li>
                <li><i class="fas fa-user-tie text-primary"></i> <strong>Responsable de la publication :</strong> [Nom du chef d'établissement / représentant légal]</li>
                <li><i class="fas fa-id-card text-primary"></i> <strong>Numéro SIRET / RNE / UAI :</strong> [Identifiant de l'établissement]</li>
            </ul>
            <p class="text-muted mt-2" style="font-size:.85rem">
                L'établissement agit en qualité de responsable de traitement au sens de l'article 4 du RGPD
                pour l'ensemble des données personnelles traitées via ce service.
            </p>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h2>Hébergeur</h2></div>
        <div class="card-body">
            <ul class="export-data-list">
                <li><i class="fas fa-server text-info"></i> <strong>Hébergeur :</strong> [Nom de l'hébergeur]</li>
                <li><i class="fas fa-map-marker-alt text-info"></i> <strong>Adresse :</strong> [Adresse de l'hébergeur]</li>
                <li><i class="fas fa-phone text-info"></i> <strong>Téléphone :</strong> [Téléphone de l'hébergeur]</li>
                <li><i class="fas fa-globe text-info"></i> <strong>Localisation des données :</strong> [Pays / zone d'hébergement, ex. Union européenne]</li>
            </ul>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h2>Délégué à la protection des données (DPO)</h2></div>
        <div class="card-body">
            <ul class="export-data-list">
                <li><i class="fas fa-user-shield text-success"></i> <strong>Délégué à la protection des données :</strong> [Nom du DPO]</li>
                <li><i class="fas fa-envelope text-success"></i> <strong>Email DPO :</strong> [Email DPO]</li>
                <li><i class="fas fa-map-marker-alt text-success"></i> <strong>Adresse postale :</strong> [Adresse à laquelle adresser les courriers relatifs aux données personnelles]</li>
            </ul>
            <p class="text-muted mt-2" style="font-size:.85rem">
                Pour toute question relative au traitement de vos données personnelles ou pour exercer
                vos droits, vous pouvez contacter le délégué à la protection des données aux coordonnées
                ci-dessus.
            </p>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h2>Propriété intellectuelle</h2></div>
        <div class="card-body">
            <p>
                L'ensemble des contenus présents sur ce service (textes, mises en page, marques, logos)
                est protégé par le droit de la propriété intellectuelle. Toute reproduction ou
                représentation, totale ou partielle, sans autorisation préalable est interdite.
            </p>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Réclamation</h2></div>
        <div class="card-body">
            <p>
                Si vous estimez, après avoir contacté l'établissement ou son délégué à la protection des
                données, que vos droits ne sont pas respectés, vous pouvez introduire une réclamation
                auprès de l'autorité de contrôle compétente : la Commission Nationale de l'Informatique
                et des Libertés (CNIL), [adresse / site web de la CNIL].
            </p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
