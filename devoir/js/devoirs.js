// devoirs.js
document.addEventListener('DOMContentLoaded', () => {
    const api = '/api/devoirs';
    const tbody = document.querySelector('tbody');
    const modal = document.getElementById('modal-devoir');
    const form = document.getElementById('form-devoir');
    const filterMatiere = document.getElementById('filtre-matiere');
    const filterClasse = document.getElementById('filtre-classe');
    const createBtn = document.getElementById('btn-creer');
    
    // Récupérer le profil utilisateur depuis la session
    let userProfile = '';
    fetch('/api/user/profile')
        .then(r => r.json())
        .then(data => {
            userProfile = data.profil;
            // Masquer le bouton de création pour les non-professeurs
            if (userProfile !== 'professeur' && userProfile !== 'administrateur') {
                createBtn.style.display = 'none';
            }
            // Charger les devoirs après avoir obtenu le profil
            loadDevoirs();
        })
        .catch(() => {
            // En cas d'erreur, on charge quand même les devoirs
            loadDevoirs();
        });
    
    // Charger la liste des matières pour le filtre
    function loadMatieres() {
        fetch('/api/matieres')
            .then(r => r.json())
            .then(data => {
                filterMatiere.innerHTML = '<option value="">Toutes matières</option>';
                data.forEach(m => {
                    const option = document.createElement('option');
                    option.value = m.nom;
                    option.textContent = m.nom;
                    filterMatiere.appendChild(option);
                });
            });
    }
    
    // Charger la liste des classes pour le filtre
    function loadClasses() {
        fetch('/api/classes')
            .then(r => r.json())
            .then(data => {
                filterClasse.innerHTML = '<option value="">Toutes classes</option>';
                // Parcourir la structure imbriquée des classes
                Object.keys(data).forEach(niveau => {
                    Object.keys(data[niveau]).forEach(cycle => {
                        data[niveau][cycle].forEach(classe => {
                            const option = document.createElement('option');
                            option.value = classe;
                            option.textContent = classe;
                            filterClasse.appendChild(option);
                        });
                    });
                });
            });
    }
    
    // Tentative de chargement des matières et classes
    loadMatieres();
    loadClasses();
    
    // Charger liste des devoirs
    function loadDevoirs(params = '') {
        tbody.innerHTML = '<tr><td colspan="5">Chargement...</td></tr>';
        
        fetch(api + params)
            .then(r => {
                if (!r.ok) {
                    throw new Error('Erreur réseau');
                }
                return r.json();
            })
            .then(data => {
                tbody.innerHTML = '';
                
                if (data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5">Aucun devoir trouvé</td></tr>';
                    return;
                }
                
                data.forEach(d => {
                    const tr = document.createElement('tr');
                    
                    // Formatter la date pour affichage
                    const dateRemise = new Date(d.date_remise);
                    const options = { 
                        weekday: 'long', 
                        year: 'numeric', 
                        month: 'long', 
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    };
                    const formattedDate = dateRemise.toLocaleDateString('fr-FR', options);
                    
                    // Déterminer si la date de remise est dépassée
                    const isOverdue = new Date() > dateRemise;
                    const dateClass = isOverdue ? 'overdue' : '';
                    
                    // Créer les boutons d'action selon le rôle
                    let actionButtons = `
                        <a href="${d.url_sujet}" target="_blank" class="btn-action">Sujet</a>
                        ${d.url_corrige ? `<a href="${d.url_corrige}" target="_blank" class="btn-action">Corrigé</a>` : ''}
                    `;
                    
                    // Ajouter les boutons d'édition/suppression pour les professeurs
                    if (userProfile === 'professeur' || userProfile === 'administrateur') {
                        actionButtons += `
                            <button class="btn-edit" data-id="${d.id}">Modifier</button>
                            <button class="btn-delete" data-id="${d.id}">Supprimer</button>
                        `;
                    }
                    
                    tr.innerHTML = `
                        <td>${d.titre}</td>
                        <td>${d.matiere}</td>
                        <td>${d.classe}</td>
                        <td class="${dateClass}">${formattedDate}</td>
                        <td>${actionButtons}</td>
                    `;
                    
                    tbody.appendChild(tr);
                });
                
                // Charger les statuts pour les élèves
                if (userProfile === 'eleve') {
                    loadDevoirStatus();
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                tbody.innerHTML = '<tr><td colspan="5">Erreur lors du chargement des devoirs</td></tr>';
            });
    }
    
    // Charger et afficher le statut des devoirs pour les élèves
    function loadDevoirStatus() {
        if (userProfile !== 'eleve') return;
        
        const statusHeaders = document.querySelectorAll('.status-header');
        if (statusHeaders.length === 0) {
            // Ajouter l'en-tête de colonne pour le statut
            const headerRow = document.querySelector('thead tr');
            const statusHeader = document.createElement('th');
            statusHeader.className = 'status-header';
            statusHeader.textContent = 'Statut';
            headerRow.insertBefore(statusHeader, headerRow.lastElementChild);
        }
        
        // Ajouter la colonne de statut à chaque ligne
        document.querySelectorAll('tbody tr').forEach(tr => {
            if (tr.querySelector('.devoir-status')) return; // Déjà traité
            
            const devoirId = tr.querySelector('[data-id]')?.dataset.id;
            if (!devoirId) return;
            
            const statusCell = document.createElement('td');
            statusCell.className = 'devoir-status';
            statusCell.innerHTML = '<div class="loading">Chargement...</div>';
            
            const actionsCell = tr.querySelector('td:last-child');
            tr.insertBefore(statusCell, actionsCell);
            
            // Charger le statut depuis l'API
            fetch(`/api/devoir_status?id_devoir=${devoirId}`)
                .then(r => r.json())
                .then(data => {
                    let statusHTML = '';
                    
                    if (data.length > 0) {
                        const status = data[0].status;
                        
                        if (status === 'non_fait') {
                            statusHTML = '<span class="status status-todo">À faire</span>';
                        } else if (status === 'en_cours') {
                            statusHTML = '<span class="status status-in-progress">En cours</span>';
                        } else if (status === 'termine') {
                            statusHTML = '<span class="status status-done">Terminé</span>';
                        }
                    }
                    
                    // Ajouter les boutons pour changer le statut
                    statusHTML += `
                        <div class="devoir-actions">
                            <button class="btn-status-update btn-status-todo" data-id="${devoirId}" data-status="non_fait">À faire</button>
                            <button class="btn-status-update btn-status-in-progress" data-id="${devoirId}" data-status="en_cours">En cours</button>
                            <button class="btn-status-update btn-status-done" data-id="${devoirId}" data-status="termine">Terminé</button>
                        </div>
                    `;
                    
                    statusCell.innerHTML = statusHTML;
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    statusCell.innerHTML = '<div class="error">Erreur</div>';
                });
        });
    }
    
    // Fonction pour mettre à jour le statut d'un devoir
    function updateDevoirStatus(devoirId, status) {
        const data = {
            id_devoir: devoirId,
            status: status
        };
        
        fetch('/api/devoir_status', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Erreur lors de la mise à jour du statut');
            }
            return response.json();
        })
        .then(() => {
            // Recharger la liste des devoirs pour afficher le nouveau statut
            loadDevoirs();
        })
        .catch(error => {
            console.error('Erreur:', error);
            alert('Erreur lors de la mise à jour du statut');
        });
    }
    
    // Ouvrir modal création
    document.getElementById('btn-creer').addEventListener('click', () => {
        form.reset();
        document.querySelector('#modal-title').textContent = 'Créer un devoir';
        document.querySelector('[name="fichier_sujet"]').required = true;
        modal.classList.remove('hidden');
    });
    
    // Fermer modal
    document.getElementById('btn-fermer').addEventListener('click', () => {
        modal.classList.add('hidden');
    });
    
    // Gestion des erreurs de formulaire
    function showFormError(message) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'form-error';
        errorDiv.textContent = message;
        
        // Retirer d'anciens messages d'erreur s'ils existent
        const oldError = form.querySelector('.form-error');
        if (oldError) oldError.remove();
        
        // Ajouter le message d'erreur en haut du formulaire
        form.insertBefore(errorDiv, form.firstChild);
    }
    
    // Soumission formulaire avec gestion des erreurs
    form.addEventListener('submit', e => {
        e.preventDefault();
        
        const fd = new FormData(form);
        const id = fd.get('id');
        const method = id ? 'PUT' : 'POST';
        const url = id ? `${api}/${id}` : api;
        
        // Vérifications basiques côté client
        const titre = fd.get('titre');
        const matiere = fd.get('matiere');
        const classe = fd.get('classe');
        const dateRemise = fd.get('date_remise');
        
        if (!titre || !matiere || !classe || !dateRemise) {
            showFormError('Veuillez remplir tous les champs obligatoires');
            return;
        }
        
        // En mode création, vérifier que le fichier sujet est fourni
        if (!id && !fd.get('fichier_sujet').name) {
            showFormError('Le fichier sujet est obligatoire');
            return;
        }
        
        fetch(url, { 
            method, 
            body: fd 
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(data => {
                    throw new Error(data.error || 'Une erreur est survenue');
                });
            }
            return response.json();
        })
        .then(() => {
            modal.classList.add('hidden');
            loadDevoirs();
            
            // Notification temporaire de succès
            const notification = document.createElement('div');
            notification.className = 'notification success';
            notification.textContent = id ? 'Devoir modifié avec succès' : 'Devoir créé avec succès';
            document.body.appendChild(notification);
            
            // Faire disparaître la notification après 3 secondes
            setTimeout(() => {
                notification.classList.add('hide');
                setTimeout(() => notification.remove(), 500);
            }, 3000);
        })
        .catch(error => {
            showFormError(error.message);
        });
    });
    
    // Édition et suppression (délégué)
    tbody.addEventListener('click', e => {
        if (e.target.classList.contains('btn-edit')) {
            fetch(api + '/' + e.target.dataset.id)
                .then(r => r.json())
                .then(d => {
                    Object.entries(d).forEach(([k, v]) => {
                        if (form[k]) form[k].value = v;
                    });
                    document.querySelector('#modal-title').textContent = 'Modifier un devoir';
                    document.querySelector('[name="fichier_sujet"]').required = false;
                    modal.classList.remove('hidden');
                });
        }
        
        if (e.target.classList.contains('btn-delete')) {
            if (confirm('Supprimer ce devoir ?')) {
                fetch(api + '/' + e.target.dataset.id, { method: 'DELETE' })
                    .then(response => {
                        if (!response.ok) {
                            return response.json().then(data => {
                                throw new Error(data.error || 'Erreur lors de la suppression');
                            });
                        }
                        return response.json();
                    })
                    .then(() => {
                        loadDevoirs();
                        
                        // Notification temporaire
                        const notification = document.createElement('div');
                        notification.className = 'notification success';
                        notification.textContent = 'Devoir supprimé avec succès';
                        document.body.appendChild(notification);
                        
                        setTimeout(() => {
                            notification.classList.add('hide');
                            setTimeout(() => notification.remove(), 500);
                        }, 3000);
                    })
                    .catch(error => {
                        alert(error.message);
                    });
            }
        }
    });
    
    // Filtrer
    document.getElementById('btn-filtrer').addEventListener('click', () => {
        const m = document.getElementById('filtre-matiere').value;
        const c = document.getElementById('filtre-classe').value;
        const d = document.getElementById('filtre-date').value;
        
        const params = `?matiere=${encodeURIComponent(m)}&classe=${encodeURIComponent(c)}&date_remise=${encodeURIComponent(d)}`;
        loadDevoirs(params);
    });
    
    // Effacer les filtres
    const btnClearFilters = document.createElement('button');
    btnClearFilters.id = 'btn-clear-filters';
    btnClearFilters.textContent = 'Effacer les filtres';
    btnClearFilters.addEventListener('click', () => {
        document.getElementById('filtre-matiere').value = '';
        document.getElementById('filtre-classe').value = '';
        document.getElementById('filtre-date').value = '';
        loadDevoirs();
    });
    document.getElementById('filtres').appendChild(btnClearFilters);
    
    // Lien vers le cahier de texte
    document.getElementById('btn-cahier-texte').addEventListener('click', () => {
        window.location.href = '/cahier_texte.html';
    });
    
    // Gestionnaire pour les boutons de statut
    document.addEventListener('click', e => {
        if (e.target.classList.contains('btn-status-update')) {
            const devoirId = e.target.dataset.id;
            const status = e.target.dataset.status;
            updateDevoirStatus(devoirId, status);
        }
    });
});