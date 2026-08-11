/**
 * Client WebSocket global — Fronote
 * Charge sur toutes les pages authentifiees via shared_header.php.
 * Lit la config depuis window.FRONOTE_WS (injectee par PHP).
 *
 * Features:
 * - Auto-reconnect with exponential backoff
 * - Heartbeat (30s) with 90s timeout detection
 * - Token refresh before expiry
 * - Real-time notifications for: messages, grades, absences, events, announcements
 * - Badge updates for sidebar modules
 * - Fallback: HTTP polling every 30s after 3 WS failures
 */
(function () {
    'use strict';

    var cfg = window.FRONOTE_WS;
    if (!cfg || !cfg.url || !cfg.token || !cfg.userId) return;

    var socket         = null;
    var connected      = false;
    var failedAttempts = 0;
    var pollingTimer   = null;
    var heartbeatTimer = null;
    var tokenRefreshTimer = null;   // handle de la chaîne scheduleTokenRefresh (une seule active)
    var tokenRefreshGen = 0;        // génération : invalide les chaînes de refresh en vol (anti-race)

    var MAX_FAILS   = 3;
    var POLL_DELAY  = 30000;
    var HEARTBEAT_INTERVAL = 30000;

    // Toast helper (uses FronoteToast from components.js, fallback to showToast)
    function toast(msg, type) {
        if (window.FronoteToast) {
            window.FronoteToast.show(msg, type || 'info');
        } else if (typeof showToast === 'function') {
            showToast(msg, type || 'info');
        }
    }

    // ─── Notifications de bureau (messagerie) ────────────────────
    // Demande de permission POLIE : une seule fois, et seulement après un premier
    // geste utilisateur (clic/touche) — jamais au chargement (bannière intrusive).
    var _notifPermAsked = false;
    function requestNotifPermissionOnce() {
        if (_notifPermAsked) return;
        if (typeof Notification === 'undefined') return;
        if (Notification.permission !== 'default') { _notifPermAsked = true; return; }
        _notifPermAsked = true;
        try { Notification.requestPermission(); } catch (e) { /* Safari legacy: callback form — on ignore */ }
    }
    var _permPromptArmed = false;
    function armPolitePermissionPrompt() {
        if (_permPromptArmed) return;                 // évite d'empiler des écouteurs à chaque reconnexion
        if (typeof Notification === 'undefined') return;
        if (Notification.permission !== 'default') return;
        _permPromptArmed = true;
        var handler = function () {
            requestNotifPermissionOnce();
            window.removeEventListener('click', handler);
            window.removeEventListener('keydown', handler);
        };
        window.addEventListener('click', handler, { once: false });
        window.addEventListener('keydown', handler, { once: false });
    }

    // Conversation en sourdine : le serveur OMET normalement les destinataires en
    // sourdine, mais on double-vérifie côté client si l'appli a exposé la liste.
    function isConversationMuted(convId) {
        if (convId == null) return false;
        var key = String(convId);
        var m = window.__mutedConversations;
        if (!m) return false;
        if (typeof m.has === 'function') return m.has(key) || m.has(Number(convId));
        if (Array.isArray(m)) return m.indexOf(key) !== -1 || m.indexOf(Number(convId)) !== -1;
        return !!m[key];
    }

    // Son de notification court, sans asset externe (CSP-safe) via WebAudio.
    // L'AudioContext ne peut démarrer qu'après un geste utilisateur : on le crée/
    // reprend paresseusement et on échoue silencieusement si le navigateur bloque.
    var _audioCtx = null;
    function playNotificationSound() {
        try {
            var AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return;
            if (!_audioCtx) _audioCtx = new AC();
            if (_audioCtx.state === 'suspended' && _audioCtx.resume) { _audioCtx.resume(); }
            var now = _audioCtx.currentTime;
            var osc = _audioCtx.createOscillator();
            var gain = _audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(660, now);
            osc.frequency.setValueAtTime(880, now + 0.08);
            gain.gain.setValueAtTime(0.0001, now);
            gain.gain.exponentialRampToValueAtTime(0.12, now + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.25);
            osc.connect(gain);
            gain.connect(_audioCtx.destination);
            osc.start(now);
            osc.stop(now + 0.26);
        } catch (e) { /* audio bloqué / indisponible → silencieux */ }
    }

    function showDesktopNotification(sender, body, convId) {
        if (typeof Notification === 'undefined' || Notification.permission !== 'granted') return;
        try {
            var n = new Notification(sender || 'Nouveau message', {
                body: body || '',
                tag: convId != null ? ('conversation-' + convId) : undefined, // regroupe par conversation
                renotify: false,
            });
            n.onclick = function () {
                try { window.focus(); } catch (e) {}
                if (convId != null) {
                    var base = window.FRONOTE_BASE_URL || '/';
                    window.location.href = base + 'modules/messagerie/conversation.php?id=' + encodeURIComponent(convId);
                }
                n.close();
            };
        } catch (e) { /* notification indisponible → silencieux */ }
    }

    // ─── Connection ──────────────────────────────────────────────

    function connect() {
        if (typeof io === 'undefined') {
            console.warn('[WS] Socket.IO unavailable — fallback polling');
            startPolling();
            return;
        }

        socket = io(cfg.url, {
            auth: { token: cfg.token },
            transports: ['websocket', 'polling'],
            reconnection: true,
            reconnectionDelay: 1000,
            reconnectionDelayMax: 30000,
            randomizationFactor: 0.5,
        });

        socket.on('connect', onConnect);
        socket.on('disconnect', onDisconnect);
        socket.on('connect_error', onConnectError);

        // ─── Global event handlers ───────────────────────────────
        socket.on('notification',  handleNotification);
        socket.on('unread_count',  updateBadge);
        socket.on('system_alert',  handleSystemAlert);

        // Real-time module events
        socket.on('grade',        handleGrade);
        socket.on('absence',      handleAbsence);
        socket.on('event',        handleEvent);
        socket.on('announcement', handleAnnouncement);
        socket.on('message',      handleMessage);
        socket.on('badge_update', handleBadgeUpdate);

        // Heartbeat
        socket.on('heartbeat:ack', function() { /* Server acknowledged */ });
        socket.on('token:refreshed', function() { console.log('[WS] Token refreshed'); });
        socket.on('token:error', function(msg) { console.warn('[WS] Token refresh error:', msg); });
    }

    // ─── Connection handlers ─────────────────────────────────────

    function onConnect() {
        connected      = true;
        failedAttempts = 0;
        stopPolling();
        startHeartbeat();

        // Les rooms nominatives et l'établissement sont rejointes CÔTÉ SERVEUR à
        // partir des claims JWT vérifiés (aucun emit client 'joinUser'/'joinEtablissement' :
        // le client ne doit pas pouvoir déclarer son établissement — anti-usurpation tenant).
        if (cfg.userRole === 'administrateur') {
            socket.emit('join:admin');
        }

        // Demande POLIE (une fois, au 1er geste) de la permission de notification
        // de bureau — pour les messages reçus hors de la conversation affichée.
        armPolitePermissionPrompt();

        console.log('[WS] Connected — user=' + cfg.userId + ' role=' + cfg.userRole);
    }

    function onDisconnect(reason) {
        connected = false;
        stopHeartbeat();
        console.warn('[WS] Disconnected:', reason);
    }

    function onConnectError() {
        failedAttempts++;
        if (failedAttempts >= MAX_FAILS) {
            console.warn('[WS] ' + MAX_FAILS + ' failures — fallback polling');
            startPolling();
        }
    }

    // ─── Heartbeat ───────────────────────────────────────────────

    function startHeartbeat() {
        stopHeartbeat();
        heartbeatTimer = setInterval(function() {
            if (socket && connected) {
                socket.emit('heartbeat');
            }
        }, HEARTBEAT_INTERVAL);

        // Schedule token refresh (5 min before expiry)
        scheduleTokenRefresh();
    }

    function stopHeartbeat() {
        if (heartbeatTimer) {
            clearInterval(heartbeatTimer);
            heartbeatTimer = null;
        }
        // Annule aussi la chaîne de refresh de token : sans cela, chaque reconnexion en
        // empilait une nouvelle et elles fetchaient toutes en parallèle indéfiniment.
        if (tokenRefreshTimer) {
            clearTimeout(tokenRefreshTimer);
            tokenRefreshTimer = null;
        }
        tokenRefreshGen++;   // invalide toute chaîne dont un fetch est en vol (son .finally ne re-planifiera pas)
    }

    function scheduleTokenRefresh() {
        // Refresh token via AJAX when near expiry. Une SEULE chaîne active, identifiée par sa
        // génération : un fetch en vol au moment d'un cycle déconnexion/reconnexion ne peut plus
        // ré-armer par-dessus la nouvelle chaîne (son gen devient périmé → son .finally s'abstient).
        var baseUrl = window.FRONOTE_BASE_URL || '/';
        if (tokenRefreshTimer) { clearTimeout(tokenRefreshTimer); }
        var gen = ++tokenRefreshGen;
        tokenRefreshTimer = setTimeout(function refreshLoop() {
            if (!connected || gen !== tokenRefreshGen) { return; }
            fetch(baseUrl + 'API/endpoints/ws_token_refresh.php', {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.ok ? r.json() : null; })
            .then(function(data) {
                if (data && data.token && socket) {
                    socket.emit('token:refresh', data.token);
                    cfg.token = data.token;
                }
            })
            .catch(function() {})
            .finally(function() {
                // Refresh every 20 minutes — uniquement si toujours connecté ET toujours la chaîne courante.
                if (connected && gen === tokenRefreshGen) {
                    tokenRefreshTimer = setTimeout(refreshLoop, 20 * 60 * 1000);
                }
            });
        }, 20 * 60 * 1000); // First refresh after 20 min
    }

    // ─── Event handlers ──────────────────────────────────────────

    function handleNotification(data) {
        var msg  = data.message || 'Nouvelle notification';
        var type = data.type    || 'info';
        toast(msg, type);
        if (typeof data.unread_count !== 'undefined') {
            updateBadge({ count: data.unread_count });
        }
    }

    function handleGrade(data) {
        var subject = data.matiere || data.subject || '';
        var note = data.note || data.grade || '';
        var max  = data.max || '20';
        var msg = 'Nouvelle note' + (subject ? ' en ' + subject : '') + (note ? ': ' + note + '/' + max : '');
        toast(msg, 'success');
        updateModuleBadge('notes');
    }

    function handleAbsence(data) {
        var msg = data.message || 'Nouvelle absence signalee';
        toast(msg, 'warning');
        updateModuleBadge('absences');
    }

    function handleEvent(data) {
        var msg = data.message || data.title || 'Nouvel evenement';
        toast(msg, 'info');
        updateModuleBadge('agenda');
    }

    function handleAnnouncement(data) {
        var msg = data.message || data.title || 'Nouvelle annonce';
        toast(msg, 'info');
        updateModuleBadge('annonces');
    }

    function handleMessage(data) {
        data = data || {};
        var convId = (typeof data.conversationId !== 'undefined') ? data.conversationId
                   : (typeof data.convId !== 'undefined') ? data.convId : null;

        // Badge global : toujours à jour si le serveur fournit un compteur.
        if (typeof data.unread_count !== 'undefined') {
            updateBadge({ count: data.unread_count });
        }

        // Conversation actuellement AFFICHÉE → la vue conversation gère l'ajout du
        // message (aucun toast/notif/son : ce serait du bruit redondant).
        var active = window.__activeConversationId;
        if (convId != null && active != null && String(convId) === String(active)) {
            return;
        }

        // En sourdine → aucune alerte (le serveur devrait déjà l'avoir omis).
        if (isConversationMuted(convId)) return;

        // Extrait lisible : nom d'expéditeur + court aperçu si disponible.
        var m = data.message || {};
        var sender = data.sender_name || data.from || m.sender_name || m.expediteur_nom || '';
        var snippet = data.preview || m.preview || m.contenu || m.body || '';
        if (typeof snippet === 'string' && snippet.length > 120) snippet = snippet.slice(0, 117) + '…';

        var title = sender ? 'Message de ' + sender : 'Nouveau message';
        toast(title + (snippet ? ' : ' + snippet : ''), 'info');
        showDesktopNotification(title, snippet, convId);
        playNotificationSound();
    }

    function handleBadgeUpdate(data) {
        if (data.module) {
            updateModuleBadge(data.module, data.count);
        }
    }

    function handleSystemAlert(data) {
        toast(data.message || 'Alerte systeme', 'warning');
    }

    // ─── Badge updates ───────────────────────────────────────────

    function updateBadge(data) {
        var badge = document.getElementById('sidebarMsgBadge');
        if (!badge) return;
        var count = parseInt(data.count || 0, 10);
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.classList.remove('sidebar-badge--hidden');
        } else {
            badge.textContent = '';
            badge.classList.add('sidebar-badge--hidden');
        }
    }

    function updateModuleBadge(moduleKey, count) {
        var badge = document.querySelector('[data-badge-module="' + moduleKey + '"]');
        if (!badge) return;
        if (typeof count === 'number') {
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.style.display = count > 0 ? '' : 'none';
        } else {
            // Increment existing
            var current = parseInt(badge.textContent || '0', 10);
            badge.textContent = String(current + 1);
            badge.style.display = '';
        }
    }

    // ─── Polling fallback ────────────────────────────────────────

    function startPolling() {
        if (pollingTimer) return;
        pollingTimer = setInterval(pollUnreadCount, POLL_DELAY);
    }

    function stopPolling() {
        if (pollingTimer) {
            clearInterval(pollingTimer);
            pollingTimer = null;
        }
    }

    function pollUnreadCount() {
        if (connected) { stopPolling(); return; }

        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var csrf     = csrfMeta ? csrfMeta.getAttribute('content') : '';
        var baseUrl  = window.FRONOTE_BASE_URL || '/';

        fetch(baseUrl + 'API/endpoints/messagerie.php?resource=notifications&action=count', {
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrf,
            }
        })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
            if (data && typeof data.count !== 'undefined') {
                updateBadge(data);
            }
        })
        .catch(function () {});
    }

    // ─── Public API ──────────────────────────────────────────────

    window.wsGlobal = {
        on: function (event, cb) { if (socket) socket.on(event, cb); },
        off: function (event, cb) { if (socket) socket.off(event, cb); },
        emit: function (event, data) { if (socket && connected) socket.emit(event, data); },
        isConnected: function () { return connected; },
        refreshBadge: pollUnreadCount,
        joinClass: function(classeId) { if (socket && connected) socket.emit('join:class', classeId); },
        joinConversation: function(convId) { if (socket && connected) socket.emit('join:conversation', convId); },
    };

    // Start
    connect();
}());
