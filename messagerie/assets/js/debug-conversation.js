/**
 * Script de débogage pour résoudre les problèmes de conversation
 */
document.addEventListener('DOMContentLoaded', function() {
    console.log("Script de débogage de conversation chargé");
    
    // 1. Fixer le problème d'affichage
    function fixMessageDisplay() {
        console.log("Correction de l'affichage des messages");
        const messagesContainer = document.querySelector('.messages-container');
        const messages = document.querySelectorAll('.message');
        
        if (messagesContainer) {
            // Assurer le scroll au chargement
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
            console.log("Scroll effectué, hauteur:", messagesContainer.scrollHeight);
            
            // Mode de disposition flex pour tous les messages
            messagesContainer.style.display = 'flex';
            messagesContainer.style.flexDirection = 'column';
        }
        
        // Parcourir tous les messages pour appliquer directement le style
        messages.forEach(message => {
            if (message.classList.contains('self')) {
                message.style.alignSelf = 'flex-end';
                message.style.backgroundColor = '#e1f8f2';
                console.log("Message aligné à droite:", message);
            } else {
                message.style.alignSelf = 'flex-start';
                console.log("Message aligné à gauche:", message);
            }
        });
    }
    
    // 2. S'assurer que la barre de réponse est visible
    function fixReplyBox() {
        console.log("Vérification de la barre de réponse");
        const replyBox = document.querySelector('.reply-box');
        if (replyBox) {
            replyBox.style.display = 'block';
            replyBox.style.position = 'sticky';
            replyBox.style.bottom = '0';
            replyBox.style.zIndex = '1000';
            console.log("Barre de réponse fixée");
        } else {
            console.log("Barre de réponse non trouvée");
        }
    }
    
    // 3. Corriger le formulaire d'envoi
    function fixSendForm() {
        console.log("Correction du formulaire d'envoi");
        const form = document.getElementById('messageForm');
        if (!form) {
            console.log("Formulaire d'envoi non trouvé");
            return;
        }
        
        // Remplacer le gestionnaire d'événement d'origine
        form.removeEventListener('submit', window.setupAjaxMessageSending);
        
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            console.log("Soumission du formulaire interceptée");
            
            const textarea = form.querySelector('textarea[name="contenu"]');
            if (!textarea || textarea.value.trim() === '') {
                alert('Le message ne peut pas être vide');
                return;
            }
            
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Envoi en cours...';
            
            console.log("Préparation de l'envoi du message");
            
            // Envoi du formulaire en utilisant AJAX traditionnel
            const xhr = new XMLHttpRequest();
            const formData = new FormData(form);
            
            xhr.open('POST', form.action || 'conversation.php?id=' + new URLSearchParams(window.location.search).get('id'), true);
            
            xhr.onload = function() {
                console.log("Réponse reçue:", xhr.status, xhr.responseText);
                
                try {
                    // Tenter d'analyser la réponse comme JSON
                    const jsonResponse = JSON.parse(xhr.responseText);
                    if (jsonResponse.success) {
                        console.log("Message envoyé avec succès");
                        // Recharger la page pour voir le nouveau message
                        window.location.reload();
                    } else {
                        console.error("Erreur dans la réponse JSON:", jsonResponse.error || "Erreur inconnue");
                        alert("Erreur lors de l'envoi du message: " + (jsonResponse.error || "Erreur inconnue"));
                    }
                } catch (e) {
                    // Si ce n'est pas du JSON, c'est probablement la page HTML complète (soumission normale)
                    console.log("Réponse non-JSON reçue, rechargement de la page");
                    window.location.reload();
                }
            };
            
            xhr.onerror = function() {
                console.error("Erreur réseau lors de l'envoi du message");
                alert("Erreur réseau lors de l'envoi du message. Veuillez réessayer.");
            };
            
            xhr.onloadend = function() {
                // Toujours réactiver le bouton
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
            };
            
            xhr.send(formData);
        });
    }
    
    // 4. Assurer que les indicateurs "Vu" sont visibles
    function fixReadIndicators() {
        console.log("Vérification des indicateurs 'Vu'");
        const readIndicators = document.querySelectorAll('.message-read');
        readIndicators.forEach(indicator => {
            indicator.style.display = 'inline-flex';
            indicator.style.visibility = 'visible';
            indicator.style.opacity = '1';
            
            // Assurer que l'icône est visible
            const icon = indicator.querySelector('i');
            if (icon) {
                icon.style.display = 'inline-block';
                icon.style.visibility = 'visible';
                icon.style.opacity = '1';
            }
            
            console.log("Indicateur 'Vu' ajusté:", indicator);
        });
        
        // S'assurer que les conteneurs sont également visibles
        document.querySelectorAll('.message-status').forEach(status => {
            status.style.display = 'flex';
            status.style.visibility = 'visible';
            status.style.minHeight = '20px';
        });
        
        document.querySelectorAll('.message-footer').forEach(footer => {
            footer.style.display = 'flex';
            footer.style.visibility = 'visible';
        });
    }
    
    // Exécuter toutes les corrections
    fixMessageDisplay();
    fixReplyBox();
    fixSendForm();
    fixReadIndicators();
    
    // Rappeler les fonctions de correction périodiquement au cas où la page change dynamiquement
    setInterval(fixMessageDisplay, 2000);
    setInterval(fixReplyBox, 2000);
    setInterval(fixReadIndicators, 2000);
});