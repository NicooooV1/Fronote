// cahier_texte.js
document.addEventListener('DOMContentLoaded', () => {
    const apiCahierTexte = '/api/cahier_texte';
    const apiDevoirs = '/api/devoirs';
    const container = document.getElementById('cahier-container');
    const modal = document.getElementById('modal-seance');
    const form = document.getElementById('form-seance');
    const filterMatiere = document.getElementById('filtre-matiere');
    const filterClasse = document.getElementById('filtre-classe');
    const createBtn = document.getElementById('btn-creer-seance');
    
    // Récupérer le profil utilisateur depuis la session
    let userProfile = '';
    fetch('/api/user/profile')
        .then(r => r.json())
        .then(data => {
            userProfile = data.profil;
            // Afficher les éléments réservés aux enseignants
            if (userProfile === 'professeur' || userProfile === 'administrateur') {
                document.querySelectorAll('.teacher-only').forEach(el => el.style.display = 'block');
            }
            // Charger le cahier de texte après avoir obtenu le profil
            loadCahierTexte();
        })
        .catch(() => {
            // En cas d'erreur, on charge quand même le cahier de texte
            loadCahierTexte();
        });
    
    // Charger la liste des matières pour le filtre
    function loadMatieres() {
        fetch('/api/matieres')
            .then(r => r.json())
            .then(data => {
                const selectMatiere = document.getElementById('filtre-matiere');
                const formMatiere = document.querySelector('form [name="matiere"]');
                
                selectMatiere.innerHTML = '<option value="">Toutes matières</option>';
                formMatiere.innerHTML = '<option value="">Sélectionnez une matière</option>';
                
                data.forEach(m => {
                    // Option pour le filtre
                    const option1 = document.createElement('option');
                    option1.value = m.nom;
                    option1.textContent = m.nom;
                    selectMatiere.appendChild(option1);
                    
                    // Option pour le formulaire
                    const option2 = document.createElement('option');
                    option2.value = m.nom;
                    option2.textContent = m.nom;
                    formMatiere.appendChild(option2);
                });
            });
    }
    
    // Charger la liste des classes pour le filtre
    function loadClasses() {
        fetch('/api/classes')
            .then(r => r.json())
            .then(data => {
                const selectClasse = document.getElementById('filtre-classe');
                const formClasse = document.querySelector('form [name="classe"]');
                
                selectClasse.innerHTML = '<option value="">Toutes classes</option>';
                formClasse.innerHTML = '<option value="">Sélectionnez une classe</option>';
                
                // Parcourir la structure imbriquée des classes
                Object.keys(data).forEach(niveau => {
                    Object.keys(data[niveau]).forEach(cycle => {
                        data[niveau][cycle].forEach(classe => {
                            // Option pour le filtre
                            const option1 = document.createElement('option');
                            option1.value = classe;
                            option1.textContent = classe;
                            selectClasse.appendChild(option1);
                            
                            // Option pour le formulaire
                            const option2 = document.createElement('option');
                            option2.value = classe;
                            option2.textContent = classe;
                            formClasse.appendChild(option2);
                        });
                    });
                });
            });
    }
    
    // Charger les devoirs associés à une classe/matière pour le formulaire
    function loadDevoirsForForm(classe, matiere) {
        const devoirsContainer = document.getElementById('devoirs-associes');
        devoirsContainer.innerHTML = '<div class="loading">Chargement des devoirs...</div>';
        
        fetch(`${apiDevoirs}?classe=${encodeURIComponent(classe)}&matiere=${encodeURIComponent(matiere)}`)
            .then(r => r.json())
            .then(data => {
                devoirsContainer.innerHTML = '<label>Devoirs associés</label>';
                
                if (data.length === 0) {
                    devoirsContainer.innerHTML += '<p>Aucun devoir disponible pour cette classe et cette matière.</p>';
                    return;
                }
                
                // Créer des checkboxes pour chaque devoir
                data.forEach(d => {
                    const checkbox = document.createElement('div');
                    
                    // Formatter la date
                    const dateRemise = new Date(d.date_remise);
                    const options = { 
                        year: 'numeric', 
                        month: 'short', 
                        day: 'numeric'
                    };
                    const formattedDate = dateRemise.toLocaleDateString('fr-FR', options);
                    
                    checkbox.innerHTML = `
                        <label>
                            <input type="checkbox" name="devoirs[]" value="${d.id}">
                            ${d.titre} (à rendre le ${formattedDate})
                        </label>
                    `;
                    
                    devoirsContainer.appendChild(checkbox);
                });
            })
            .catch(error => {
                console.error('Erreur:', error);
                devoirsContainer.innerHTML = '<p>Erreur lors du chargement des devoirs.</p>';
            });
    }
    
    // Charger les matières et classes
    loadMatieres();
    loadClasses();
    
    // Charger le cahier de texte
    function loadCahierTexte(params = '') {
        container.innerHTML = '<div class="loading">Chargement...</div>';
        
        fetch(apiCahierTexte + params)
            .then(r => {
                if (!r.ok) {
                    throw new Error('Erreur réseau');
                }
                return r.json();
            })
            .then(data => {
                container.innerHTML = '';
                
                if (data.length === 0) {
                    container.innerHTML = '<p>Aucune entrée trouvée dans le cahier de texte.</p>';
                    return;
                }
                
                data.forEach(entry => {
                    const div = document.createElement('div');
                    div.className = 'cahier-entry';
                    
                    // Formatter la date
                    const dateCours = new Date(entry.date_cours);
                    const options = { 
                        weekday: 'long', 
                        year: 'numeric', 
                        month: 'long', 
                        day: 'numeric'
                    };
                    const formattedDate = dateCours.toLocaleDateString('fr-FR', options);
                    
                    // Boutons d'action selon le rôle
                    let actionButtons = '';
                    if (userProfile === 'professeur' || userProfile === 'administrateur') {
                        actionButtons = `
                            <div>
                                <button class="btn-edit" data-id="${entry.id}">Modifier</button>
                                <button class="btn-delete" data-id="${entry.id}">Supprimer</button>
                            </div>
                        `;
                    }
                    
                    // Documents joints
                    let documentsHTML = '';
                    if (entry.documents) {
                        const docs = entry.documents.split(',').map(d => d.trim());
                        documentsHTML = `
                            <div class="cahier-docs">
                                <h4>Documents:</h4>
                                <ul>
                                    ${docs.map(doc => `<li><a href="${doc}" target="_blank">${doc.split('/').pop()}</a></li>`).join('')}
                                </ul>
                            </div>
                        `;
                    }
                    
                    div.innerHTML = `
                        <div class="cahier-header">
                            <div class="cahier-meta">
                                <span><strong>${entry.classe}</strong></span>
                                <span>${entry.matiere}</span>
                                <span>${formattedDate}</span>
                            </div>
                            ${actionButtons}
                        </div>
                        
                        <div class="cahier-content">
                            ${entry.contenu}
                        </div>
                        
                        ${documentsHTML}
                    `;
                    
                    container.appendChild(div);
                });
            })
            .catch(error => {
                console.error('Erreur:', error);
                container.innerHTML = '<p>Erreur lors du chargement du cahier de texte.</p>';
            });
    }
    
    // Ouvrir modal création
    document.getElementById('btn-creer-seance').addEventListener('click', () => {
        form.reset();
        document.querySelector('#modal-title').textContent = 'Nouvelle séance';
        modal.classList.remove('hidden');
    });
    
    // Fermer modal
    document.getElementById('btn-fermer').addEventListener('click', () => {
        modal.classList.add('hidden');
    });
    
    // Chargement dynamique des devoirs quand on change la classe ou la matière
    form.querySelector('[name="classe"]').addEventListener('change', updateDevoirs);
    form.querySelector('[name="matiere"]').addEventListener('change', updateDevoirs);
    
    function updateDevoirs() {
        const classe = form.querySelector('[name="classe"]').value;
        const matiere = form.querySelector('[name="matiere"]').value;
        
        if (classe && matiere) {
            loadDevoirsForForm(classe, matiere);
        }
    }
    
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
    
    // Soumission formulaire
    form.addEventListener('submit', e => {
        e.preventDefault();
        
        const id = form.querySelector('[name="id"]').value;
        const method = id ? 'PUT' : 'POST';
        const url = id ? `${apiCahierTexte}/${id}` : apiCahierTexte;
        
        // Récupérer les devoirs sélectionnés
        const devoirs = [];
        form.querySelectorAll('[name="devoirs[]"]:checked').forEach(el => {
            devoirs.push(el.value);
        });
        
        // Construire l'objet de données
        const data = {
            matiere: form.querySelector('[name="matiere"]').value,
            classe: form.querySelector('[name="classe"]').value,
            date_cours: form.querySelector('[name="date_cours"]').value,
            contenu: form.querySelector('[name="contenu"]').value,
            documents: form.querySelector('[name="documents"]').value,
            devoirs: devoirs
        };
        
        // Vérifications basiques
        if (!data.matiere || !data.classe || !data.date_cours || !data.contenu) {
            showFormError('Veuillez remplir tous les champs obligatoires');
            return;
        }
        
        fetch(url, { 
            method, 
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
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
            loadCahierTexte();
            
            // Notification temporaire de succès
            const notification = document.createElement('div');
            notification.className = 'notification success';
            notification.textContent = id ? 'Séance modifiée avec succès' : 'Séance créée avec succès';
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
    container.addEventListener('click', e => {
        if (e.target.classList.contains('btn-edit')) {
            const id = e.target.dataset.id;
            fetch(`${apiCahierTexte}/${id}`)
                .then(r => r.json())
                .then(entry => {
                    form.reset();
                    form.querySelector('[name="id"]').value = entry.id;
                    form.querySelector('[name="matiere"]').value = entry.matiere;
                    form.querySelector('[name="classe"]').value = entry.classe;
                    form.querySelector('[name="date_cours"]').value = entry.date_cours;
                    form.querySelector('[name="contenu"]').value = entry.contenu;
                    form.querySelector('[name="documents"]').value = entry.documents || '';
                    
                    // Charger les devoirs associés
                    loadDevoirsForForm(entry.classe, entry.matiere);
                    
                    document.querySelector('#modal-title').textContent = 'Modifier une séance';
                    modal.classList.remove('hidden');
                });
        }
        
        if (e.target.classList.contains('btn-delete')) {
            if (confirm('Supprimer cette entrée du cahier de texte ?')) {
                const id = e.target.dataset.id;
                fetch(`${apiCahierTexte}/${id}`, { method: 'DELETE' })
                    .then(response => {
                        if (!response.ok) {
                            return response.json().then(data => {
                                throw new Error(data.error || 'Erreur lors de la suppression');
                            });
                        }
                        return response.json();
                    })
                    .then(() => {
                        loadCahierTexte();
                        
                        // Notification temporaire
                        const notification = document.createElement('div');
                        notification.className = 'notification success';
                        notification.textContent = 'Entrée supprimée avec succès';
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
        
        const params = `?matiere=${encodeURIComponent(m)}&classe=${encodeURIComponent(c)}&date_cours=${encodeURIComponent(d)}`;
        loadCahierTexte(params);
    });
    
    // Lien vers les devoirs
    document.getElementById('btn-retour-devoirs').addEventListener('click', () => {
        window.location.href = '/devoirs';
    });
});