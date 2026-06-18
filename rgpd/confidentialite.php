<?php
/**
 * M23 – RGPD — Politique de confidentialité (Art. 12-14 Information)
 * Page statique. Les coordonnées concrètes appartiennent à l'établissement :
 * elles sont laissées en PLACEHOLDERS entre crochets.
 */
$pageTitle = 'Politique de confidentialité';
$activePage = 'confidentialite';
require_once __DIR__ . '/includes/header.php';
?>

<div class="content-wrapper">
    <div class="content-header">
        <h1><i class="fas fa-user-shield"></i> Politique de confidentialité</h1>
    </div>

    <div class="rgpd-info-banner">
        <i class="fas fa-shield-alt"></i>
        <p>
            La présente politique décrit, conformément aux articles 12 à 14 du RGPD, comment
            [Nom de l'établissement] collecte, utilise, conserve et protège vos données personnelles,
            ainsi que les droits dont vous disposez et la manière de les exercer.
        </p>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h2>Responsable de traitement</h2></div>
        <div class="card-body">
            <p>
                Le responsable du traitement de vos données est [Nom de l'établissement], situé
                [Adresse]. Pour toute question, vous pouvez contacter le délégué à la protection des
                données (DPO) à l'adresse [Email DPO]. Les coordonnées complètes figurent sur la page
                <a href="/rgpd/mentions_legales.php">Mentions légales</a>.
            </p>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h2>Finalités du traitement</h2></div>
        <div class="card-body">
            <p>Vos données sont traitées pour les finalités suivantes :</p>
            <ul class="export-data-list">
                <li><i class="fas fa-graduation-cap text-primary"></i> Gestion de la scolarité (notes, bulletins, absences, emploi du temps)</li>
                <li><i class="fas fa-user-check text-primary"></i> Gestion administrative des élèves, des familles et des personnels</li>
                <li><i class="fas fa-envelope text-primary"></i> Communication entre l'établissement, les élèves et les responsables légaux</li>
                <li><i class="fas fa-lock text-primary"></i> Sécurité, authentification et traçabilité des accès</li>
                <li><i class="fas fa-balance-scale text-primary"></i> Respect des obligations légales et réglementaires de l'établissement</li>
            </ul>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h2>Base légale</h2></div>
        <div class="card-body">
            <p>Selon le traitement concerné, la base légale est l'une des suivantes (Art. 6 du RGPD) :</p>
            <ul class="export-data-list">
                <li><i class="fas fa-gavel text-info"></i> <strong>Obligation légale</strong> — traitements imposés par la réglementation applicable à l'établissement scolaire</li>
                <li><i class="fas fa-tasks text-info"></i> <strong>Mission d'intérêt public</strong> — exécution de la mission de service public d'enseignement</li>
                <li><i class="fas fa-file-signature text-info"></i> <strong>Exécution d'un contrat</strong> — gestion de l'inscription et de la scolarité</li>
                <li><i class="fas fa-check-circle text-info"></i> <strong>Consentement</strong> — pour les traitements facultatifs (droit à l'image, communications optionnelles, etc.)</li>
            </ul>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h2>Catégories de données collectées</h2></div>
        <div class="card-body">
            <ul class="export-data-list">
                <li><i class="fas fa-id-card text-primary"></i> <strong>Données d'identité</strong> — nom, prénom, date de naissance, identifiant</li>
                <li><i class="fas fa-address-book text-primary"></i> <strong>Coordonnées</strong> — adresse postale, email, téléphone</li>
                <li><i class="fas fa-school text-primary"></i> <strong>Données de scolarité</strong> — classe, notes, bulletins, absences, retards, incidents</li>
                <li><i class="fas fa-users text-primary"></i> <strong>Données relatives aux familles</strong> — responsables légaux, liens de parenté</li>
                <li><i class="fas fa-desktop text-primary"></i> <strong>Données techniques</strong> — journaux de connexion, historique d'activité</li>
                <li><i class="fas fa-notes-medical text-danger"></i> <strong>Données particulières</strong> — le cas échéant, données de santé / besoins particuliers, soumises à des garanties renforcées</li>
            </ul>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h2>Durées de conservation</h2></div>
        <div class="card-body">
            <p>
                Vos données sont conservées pour une durée n'excédant pas celle nécessaire aux finalités
                pour lesquelles elles sont traitées, conformément à la politique de rétention de
                l'établissement. Le détail des durées par catégorie de données est précisé dans la
                <a href="/rgpd/retention.php">politique de rétention</a>.
            </p>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h2>Destinataires des données</h2></div>
        <div class="card-body">
            <p>Vos données sont accessibles uniquement aux personnes habilitées :</p>
            <ul class="export-data-list">
                <li><i class="fas fa-chalkboard-teacher text-info"></i> Personnels de l'établissement dans la limite de leurs attributions (enseignants, vie scolaire, administration)</li>
                <li><i class="fas fa-user-friends text-info"></i> L'élève et ses responsables légaux pour les données les concernant</li>
                <li><i class="fas fa-server text-info"></i> Le prestataire d'hébergement, en qualité de sous-traitant, dans le cadre d'un contrat conforme à l'article 28 du RGPD</li>
                <li><i class="fas fa-landmark text-info"></i> Les autorités administratives ou judiciaires lorsque la loi l'impose</li>
            </ul>
            <p class="text-muted mt-2" style="font-size:.85rem">
                Vos données ne sont pas vendues ni cédées à des tiers à des fins commerciales.
            </p>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h2>Vos droits et comment les exercer</h2></div>
        <div class="card-body">
            <p>Conformément aux articles 15 à 22 du RGPD, vous disposez des droits suivants :</p>
            <ul class="export-data-list">
                <li><i class="fas fa-eye text-success"></i> <strong>Droit d'accès</strong> — obtenir une copie de vos données (voir <a href="/rgpd/export_donnees.php">Exporter mes données</a>)</li>
                <li><i class="fas fa-pen text-success"></i> <strong>Droit de rectification</strong> — corriger des données inexactes</li>
                <li><i class="fas fa-trash text-success"></i> <strong>Droit à l'effacement</strong> — dans les conditions prévues par la loi</li>
                <li><i class="fas fa-pause-circle text-success"></i> <strong>Droit à la limitation</strong> et <strong>droit d'opposition</strong></li>
                <li><i class="fas fa-file-export text-success"></i> <strong>Droit à la portabilité</strong> de vos données</li>
                <li><i class="fas fa-check-circle text-success"></i> <strong>Retrait du consentement</strong> à tout moment (voir <a href="/rgpd/consentements.php">Mes consentements</a>)</li>
            </ul>
            <p class="mt-2">
                Pour exercer ces droits, adressez votre demande au délégué à la protection des données à
                l'adresse [Email DPO] ou via les outils mis à disposition dans cet espace. Vous pouvez
                également introduire une réclamation auprès de la CNIL.
            </p>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h2>Données concernant les mineurs</h2></div>
        <div class="card-body">
            <p>
                Ce service traite des données concernant des élèves mineurs. Pour ces derniers, l'exercice
                des droits et le recueil des consentements relevant de traitements facultatifs ou sensibles
                (données de santé, droit à l'image, géolocalisation) sont assurés par le ou les
                représentants légaux. L'établissement veille à limiter la collecte aux données strictement
                nécessaires et à les protéger par des mesures de sécurité appropriées.
            </p>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Cookies</h2></div>
        <div class="card-body">
            <p>
                Ce service utilise uniquement des cookies strictement nécessaires à son fonctionnement,
                notamment le cookie de session permettant de maintenir votre authentification. Ces cookies
                ne nécessitent pas de consentement préalable. Aucun cookie publicitaire ou de suivi tiers
                n'est déposé.
            </p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
