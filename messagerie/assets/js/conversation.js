/**
 * /assets/js/conversation.js - Scripts pour les conversations
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialiser les actions de conversation
    initConversationActions();
    
    // Actualisation en temps réel pour les modifications de conversation
    setupRealTimeUpdates();
});

/**
 * Initialise les actions principales de conversation
 */
function initConversationActions() {
    // Archiver une conversation
    const archiveBtn = document.getElementById('archive-btn');
    if (archiveBtn) {
        archiveBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (confirm('Êtes-vous sûr de vouloir archiver cette conversation ?')) {
                document.getElementById('archiveForm').submit();
            }
        });
    }
    
    // Supprimer une conversation
    const deleteBtn = document.getElementById('delete-btn');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (confirm('Êtes-vous sûr de vouloir supprimer cette conversation ?')) {
                document.getElementById('deleteForm').submit();
            }
        });
    }
    
    // Restaurer une conversation
    const restoreBtn = document.getElementById('restore-btn');
    if (restoreBtn) {
        restoreBtn.addEventListener('click', function(e) {
            e.preventDefault();
            document.getElementById('restoreForm').submit();
        });
    }
    
    // Gestion du modal pour l'ajout de participants
    const addParticipantBtn = document.getElementById('add-participant-btn');
    if (addParticipantBtn) {
        addParticipantBtn.addEventListener('click', function(e) {
            e.preventDefault();
            showAddParticipantModal();
        });
    }
    
    // Gestion de la fermeture du modal
    const closeModalBtns = document.querySelectorAll('.close');
    closeModalBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            closeAddParticipantModal();
        });
    });
    
    // Fermeture du modal en cliquant en dehors
    window.addEventListener('click', function(event) {
        const modals = document.querySelectorAll('.modal');
        modals.forEach(modal => {
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        });
    });
    
    // Empêcher la soumission multiple du formulaire
    const messageForm = document.getElementById('messageForm');
    if (messageForm) {
        messageForm.addEventListener('submit', function(e) {
            // Désactiver le bouton d'envoi après soumission
            const submitButton = this.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Envoi en cours...';
        });
    }
}

/**
 * Affiche le modal d'ajout de participants
 */
function showAddParticipantModal() {
    const modal = document.getElementById('addParticipantModal');
    if (modal) {
        modal.style.display = 'block';
    }
}

/**
 * Ferme le modal d'ajout de participants
 */
function closeAddParticipantModal() {
    const modal = document.getElementById('addParticipantModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

/**
 * Répond à un message spécifique
 * @param {number} messageId - ID du message
 * @param {string} senderName - Nom de l'expéditeur
 */
function replyToMessage(messageId, senderName) {
    // Montrer l'interface de réponse
    const replyInterface = document.getElementById('reply-interface');
    const replyTo = document.getElementById('reply-to');
    const textarea = document.querySelector('textarea[name="contenu"]');
    
    if (replyInterface && replyTo && textarea) {
        replyInterface.style.display = 'block';
        replyTo.textContent = 'Répondre à ' + senderName;
        
        // Stocker l'ID du message parent
        document.getElementById('parent-message-id').value = messageId;
        
        // Faire défiler vers le bas et mettre le focus sur le textarea
        textarea.focus();
        window.scrollTo(0, document.body.scrollHeight);
    }
}

/**
 * Annule une réponse à un message spécifique
 */
function cancelReply() {
    const replyInterface = document.getElementById('reply-interface');
    if (replyInterface) {
        replyInterface.style.display = 'none';
        document.getElementById('parent-message-id').value = '';
    }
}

/**
 * Promeut un participant au rôle de modérateur
 * @param {number} participantId - ID du participant
 */
function promoteToModerator(participantId) {
    if (confirm('Êtes-vous sûr de vouloir promouvoir ce participant en modérateur ?')) {
        document.getElementById('promote_participant_id').value = participantId;
        document.getElementById('promoteForm').submit();
    }
}

/**
 * Rétrograde un modérateur
 * @param {number} participantId - ID du participant
 */
function demoteFromModerator(participantId) {
    if (confirm('Êtes-vous sûr de vouloir rétrograder ce modérateur ?')) {
        document.getElementById('demote_participant_id').value = participantId;
        document.getElementById('demoteForm').submit();
    }
}

/**
 * Supprime un participant de la conversation
 * @param {number} participantId - ID du participant
 */
function removeParticipant(participantId) {
    if (confirm('Êtes-vous sûr de vouloir supprimer ce participant de la conversation ? Il n\'aura plus accès à cette conversation.')) {
        document.getElementById('remove_participant_id').value = participantId;
        document.getElementById('removeForm').submit();
    }
}

/**
 * Charge les participants disponibles selon le type sélectionné
 */
function loadParticipants() {
    const type = document.getElementById('participant_type').value;
    const select = document.getElementById('participant_id');
    const convId = new URLSearchParams(window.location.search).get('id');
    
    if (!type || !select || !convId) return;
    
    // Vider la liste actuelle
    select.innerHTML = '<option value="">Chargement...</option>';
    
    // Faire une requête AJAX pour récupérer les participants
    fetch(`api/get_participants.php?type=${type}&conv_id=${convId}`)
        .then(response => response.json())
        .then(data => {
            select.innerHTML = '';
            
            if (data.length === 0) {
                select.innerHTML = '<option value="">Aucun participant disponible</option>';
                return;
            }
            
            select.innerHTML = '<option value="">Sélectionner un participant</option>';
            
            data.forEach(participant => {
                const option = document.createElement('option');
                option.value = participant.id;
                option.textContent = participant.nom_complet;
                select.appendChild(option);
            });
        })
        .catch(error => {
            select.innerHTML = '<option value="">Erreur lors du chargement</option>';
            console.error('Erreur:', error);
        });
}

/**
 * Configure les mises à jour en temps réel pour la conversation
 */
function setupRealTimeUpdates() {
    // Vérifier les mises à jour toutes les 5 secondes
    const updateInterval = setInterval(checkForUpdates, 3000);
        
    function checkForUpdates() {
        const convId = new URLSearchParams(window.location.search).get('id');
        if (!convId) return;
        
        // Récupérer l'horodatage du dernier message affiché
        const lastMessage = document.querySelector('.message:last-child');
        const lastMessageTimestamp = lastMessage ? lastMessage.getAttribute('data-timestamp') || 0 : 0;
        
        fetch(`api/get_conversation_updates.php?conv_id=${convId}&last_timestamp=${lastMessageTimestamp}`)
            .then(response => response.json())
            .then(data => {
                if (data.hasUpdates) {
                    // Si des mises à jour sont disponibles, actualiser la page
                    location.reload();
                }
                
                // Si un utilisateur a été promu/rétrogradé, actualiser la liste des participants
                if (data.participantsChanged) {
                    refreshParticipantsList(convId);
                }
            })
            .catch(error => console.error('Erreur lors de la vérification des mises à jour:', error));
    }
    
    function refreshParticipantsList(convId) {
        fetch(`api/get_participants_list.php?conv_id=${convId}`)
            .then(response => response.text())
            .then(html => {
                const participantsList = document.querySelector('.participants-list');
                if (participantsList) {
                    participantsList.innerHTML = html;
                }
            })
            .catch(error => console.error('Erreur lors de l\'actualisation des participants:', error));
    }
    
    // Arrêter les mises à jour lorsque l'utilisateur quitte la page
    window.addEventListener('beforeunload', () => {
        clearInterval(updateInterval);
    });
}

// Gestion des pièces jointes
document.addEventListener('DOMContentLoaded', function() {
    const attachmentsInput = document.getElementById('attachments');
    if (attachmentsInput) {
        attachmentsInput.addEventListener('change', function(e) {
            const fileList = document.getElementById('file-list');
            fileList.innerHTML = '';
            
            if (this.files.length > 0) {
                for (let i = 0; i < this.files.length; i++) {
                    const file = this.files[i];
                    const fileSize = formatFileSize(file.size);
                    
                    const fileInfo = document.createElement('div');
                    fileInfo.className = 'file-info';
                    fileInfo.innerHTML = `
                        <i class="fas fa-file"></i>
                        <span>${file.name} (${fileSize})</span>
                    `;
                    fileList.appendChild(fileInfo);
                }
            }
        });
    }
});

/**
 * Formater la taille des fichiers
 * @param {number} bytes - Taille en octets
 * @returns {string} Taille formatée avec unité
 */
function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    else if (bytes < 1048576) return Math.round(bytes / 1024) + ' KB';
    else return Math.round(bytes / 1048576 * 10) / 10 + ' MB';
}