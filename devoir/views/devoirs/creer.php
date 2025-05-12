<?php
/**
 * Vue pour créer un nouveau devoir
 */

// Définir le titre de la page et les fichiers CSS/JS supplémentaires
$pageTitle = "Créer un devoir";
$extraCss = ["devoirs.css"];
$extraJs = ["devoirs.js", "upload.js"];
$currentPage = "devoirs_creer";
$includeTinyMCE = true; // Inclure l'éditeur de texte enrichi

// Vérifier les permissions (seuls les professeurs peuvent créer des devoirs)
if ($_SESSION['user_type'] !== TYPE_PROFESSEUR && !$_SESSION['is_admin']) {
    // Rediriger vers la liste des devoirs si l'utilisateur n'est pas autorisé
    header('Location: ' . BASE_URL . '/devoirs/index.php');
    exit;
}

// Inclure l'en-tête
require_once ROOT_PATH . '/includes/header.php';
?>

<div class="page-header">
    <h1>Créer un devoir</h1>
    <div class="page-actions">
        <a href="<?php echo BASE_URL; ?>/devoirs/index.php" class="btn btn-primary">
            <i class="material-icons">arrow_back</i> Retour à la liste
        </a>
    </div>
</div>

<div class="devoir-form">
    <form action="<?php echo BASE_URL; ?>/devoirs/traitement_creer.php" method="POST" enctype="multipart/form-data">
        <div class="form-tabs">
            <div class="form-tab active" data-target="tab-general">Général</div>
            <div class="form-tab" data-target="tab-contenu">Contenu</div>
            <div class="form-tab" data-target="tab-pieces-jointes">Pièces jointes</div>
            <div class="form-tab" data-target="tab-options">Options</div>
        </div>
        
        <!-- Onglet Général -->
        <div id="tab-general" class="tab-content active">
            <div class="form-group">
                <label for="titre" class="form-label">Titre du devoir</label>
                <input type="text" name="titre" id="titre" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label for="classe_id" class="form-label">Classe</label>
                <select name="classe_id" id="classe_id" class="form-select" required>
                    <option value="">Sélectionner une classe</option>
                    <?php foreach ($classes as $classe): ?>
                        <option value="<?php echo $classe['id']; ?>">
                            <?php echo htmlspecialchars($classe['nom']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group" id="groupe-container" style="display: none;">
                <label class="form-label">Groupes (optionnel)</label>
                <div class="checkbox-group" id="groupes-liste">
                    <!-- Les groupes seront chargés dynamiquement via JavaScript -->
                </div>
            </div>
            
            <div class="date-time-group">
                <div class="form-group">
                    <label for="date_debut" class="form-label">Date de début</label>
                    <input type="datetime-local" name="date_debut" id="date_debut" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="date_limite" class="form-label">Date limite</label>
                    <input type="datetime-local" name="date_limite" id="date_limite" class="form-control" required>
                </div>
            </div>
        </div>
        
        <!-- Onglet Contenu -->
        <div id="tab-contenu" class="tab-content">
            <div class="form-group">
                <label for="description" class="form-label">Description</label>
                <textarea name="description" id="description" class="form-control tinymce" rows="5"></textarea>
            </div>
            
            <div class="form-group">
                <label for="instructions" class="form-label">Instructions (facultatif)</label>
                <textarea name="instructions" id="instructions" class="form-control tinymce" rows="10"></textarea>
                <small class="form-text text-muted">Instructions détaillées pour réaliser le devoir.</small>
            </div>
        </div>
        
        <!-- Onglet Pièces jointes -->
        <div id="tab-pieces-jointes" class="tab-content">
            <div class="dropzone">
                <div class="dropzone-placeholder">
                    <div class="dropzone-icon">
                        <i class="material-icons">cloud_upload</i>
                    </div>
                    <div class="dropzone-text">
                        Glissez et déposez des fichiers ici ou cliquez pour sélectionner
                    </div>
                    <div class="dropzone-info">
                        Formats acceptés: PDF, Word, Excel, PowerPoint, Image
                    </div>
                </div>
                <input type="file" name="fichiers[]" class="dropzone-input" multiple>
                <div class="file-list" style="display: none;"></div>
            </div>
            
            <div class="pieces-jointes-list mt-3">
                <!-- La liste des fichiers sélectionnés s'affichera ici -->
            </div>
        </div>
        
        <!-- Onglet Options -->
        <div id="tab-options" class="tab-content">
            <div class="form-group">
                <div class="form-check">
                    <input type="checkbox" name="travail_groupe" id="travail_groupe" class="form-check-input" value="1">
                    <label for="travail_groupe" class="form-check-label">Travail de groupe</label>
                </div>
                <small class="form-text text-muted">Si coché, les élèves pourront collaborer en groupe pour ce devoir.</small>
            </div>
            
            <div class="form-group">
                <div class="form-check">
                    <input type="checkbox" name="est_obligatoire" id="est_obligatoire" class="form-check-input" value="1" checked>
                    <label for="est_obligatoire" class="form-check-label">Devoir obligatoire</label>
                </div>
            </div>
            
            <div class="form-group">
                <div class="form-check">
                    <input type="checkbox" name="est_visible" id="est_visible" class="form-check-input" value="1" checked>
                    <label for="est_visible" class="form-check-label">Visible pour les élèves</label>
                </div>
                <small class="form-text text-muted">Si décoché, le devoir sera masqué pour les élèves jusqu'à ce que vous le rendiez visible.</small>
            </div>
            
            <div class="form-group">
                <label for="confidentialite" class="form-label">Confidentialité des rendus</label>
                <select name="confidentialite" id="confidentialite" class="form-select">
                    <option value="classe">Visible par la classe</option>
                    <option value="eleve" selected>Visible uniquement par l'élève et le professeur</option>
                </select>
                <small class="form-text text-muted">Détermine qui peut voir les rendus des autres élèves.</small>
            </div>
            
            <div class="form-group">
                <label for="bareme" class="form-label">Barème (facultatif)</label>
                <input type="number" name="bareme" id="bareme" class="form-control" min="0" step="0.5" placeholder="20">
                <small class="form-text text-muted">Laissez vide pour ne pas utiliser de barème.</small>
            </div>
        </div>
        
        <div class="form-actions mt-4">
            <button type="submit" class="btn btn-primary">
                <i class="material-icons">save</i> Créer le devoir
            </button>
            <a href="<?php echo BASE_URL; ?>/devoirs/index.php" class="btn btn-accent">
                <i class="material-icons">cancel</i> Annuler
            </a>
        </div>
    </form>
</div>

<?php
// Script JavaScript pour la page
$pageScript = "
    // Gérer les onglets du formulaire
    document.querySelectorAll('.form-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            // Retirer la classe active de tous les onglets
            document.querySelectorAll('.form-tab').forEach(function(t) {
                t.classList.remove('active');
            });
            
            // Ajouter la classe active à l'onglet cliqué
            this.classList.add('active');
            
            // Masquer tous les contenus d'onglet
            document.querySelectorAll('.tab-content').forEach(function(content) {
                content.classList.remove('active');
            });
            
            // Afficher le contenu correspondant à l'onglet
            const target = this.getAttribute('data-target');
            document.getElementById(target).classList.add('active');
        });
    });
    
    // Charger les groupes lorsqu'une classe est sélectionnée
    document.getElementById('classe_id').addEventListener('change', function() {
        const classeId = this.value;
        if (!classeId) {
            document.getElementById('groupe-container').style.display = 'none';
            return;
        }
        
        // Requête AJAX pour récupérer les groupes de la classe
        const xhr = new XMLHttpRequest();
        xhr.open('GET', '".BASE_URL."/api/groupes.php?classe_id=' + classeId, true);
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    const groupes = JSON.parse(xhr.responseText);
                    const groupesListe = document.getElementById('groupes-liste');
                    groupesListe.innerHTML = '';
                    
                    if (groupes.length > 0) {
                        document.getElementById('groupe-container').style.display = 'block';
                        groupes.forEach(function(groupe) {
                            const checkbox = document.createElement('div');
                            checkbox.className = 'form-check';
                            checkbox.innerHTML = `
                                <input type='checkbox' name='groupes[]' id='groupe_${groupe.id}' class='form-check-input' value='${groupe.id}'>
                                <label for='groupe_${groupe.id}' class='form-check-label'>${groupe.nom}</label>
                            `;
                            groupesListe.appendChild(checkbox);
                        });
                    } else {
                        document.getElementById('groupe-container').style.display = 'none';
                    }
                } catch (e) {
                    console.error('Erreur de parsing JSON:', e);
                }
            }
        };
        xhr.send();
    });
    
    // Définir les dates par défaut
    document.addEventListener('DOMContentLoaded', function() {
        const now = new Date();
        
        // Date et heure de début: maintenant
        const dateDebut = new Date(now);
        document.getElementById('date_debut').value = formatDateTimeForInput(dateDebut);
        
        // Date et heure de fin: une semaine plus tard, même heure
        const dateLimite = new Date(now);
        dateLimite.setDate(dateLimite.getDate() + 7);
        document.getElementById('date_limite').value = formatDateTimeForInput(dateLimite);
    });
    
    // Formater la date et l'heure pour l'input datetime-local
    function formatDateTimeForInput(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        
        return `${year}-${month}-${day}T${hours}:${minutes}`;
    }
";

// Inclure le pied de page
require_once ROOT_PATH . '/includes/footer.php';
?>