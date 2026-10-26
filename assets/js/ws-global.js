/**
 * Fronote — connexion temps réel (Socket.IO), une par onglet.
 *
 * Rôle unique : maintenir la connexion et REDIFFUSER chaque événement serveur sous forme
 * d'événement DOM, que les interfaces écoutent :
 *   fronote:notification     (centre de notifications : cloche, toasts, alertes bureau)
 *   fronote:message          (messagerie : nouveau message)
 *   fronote:message-updated  fronote:read  fronote:typing
 *   fronote:ws               (état : { connected: bool })
 * Config : window.FRONOTE_WS (injectée par shared_header.php). Sans config → rien (les
 * interfaces basculent sur leur lecture périodique).
 */
(function () {
    'use strict';
    var cfg = window.FRONOTE_WS;
    if (!cfg || !cfg.url || !cfg.token || !cfg.userId) return;
    if (typeof io === 'undefined') return;

    var socket = null, connected = false, heartbeat = null, refreshTimer = null;
    var base = window.FRONOTE_BASE_URL || '/';

    function fire(name, detail) {
        try { document.dispatchEvent(new CustomEvent(name, { detail: detail || {} })); } catch (e) {}
    }

    socket = io(cfg.url, {
        auth: { token: cfg.token },
        transports: ['websocket', 'polling'],
        reconnection: true, reconnectionDelay: 1000, reconnectionDelayMax: 30000, randomizationFactor: 0.5,
    });

    socket.on('connect', function () {
        connected = true;
        clearInterval(heartbeat);
        heartbeat = setInterval(function () { if (connected) socket.emit('heartbeat'); }, 25000);
        if (cfg.userRole === 'administrateur') socket.emit('join:admin');
        // conversation ouverte : la (re)joindre après une reconnexion
        if (window.__activeConversationId) socket.emit('join:conversation', window.__activeConversationId);
        scheduleRefresh();
        fire('fronote:ws', { connected: true });
    });
    socket.on('disconnect', function () { connected = false; clearInterval(heartbeat); fire('fronote:ws', { connected: false }); });
    socket.on('connect_error', function () { fire('fronote:ws', { connected: false }); });

    socket.on('notification', function (d) { fire('fronote:notification', d); });
    socket.on('message', function (d) { fire('fronote:message', d); });
    socket.on('message:updated', function (d) { fire('fronote:message-updated', d); });
    socket.on('read', function (d) { fire('fronote:read', d); });
    socket.on('typing', function (d) { fire('fronote:typing', d); });
    // canaux historiques : ils déclenchent une simple actualisation du compteur
    ['grade', 'absence', 'event', 'announcement', 'badge_update', 'unread_count'].forEach(function (ch) {
        socket.on(ch, function (d) { fire('fronote:notification', { refresh: true, legacy: ch, data: d }); });
    });

    // Jeton : renouvellement avant expiration (une seule chaîne active)
    function scheduleRefresh() {
        clearTimeout(refreshTimer);
        refreshTimer = setTimeout(function loop() {
            if (!connected) return;
            fetch(base + 'API/endpoints/ws_token_refresh.php', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (j) { if (j && j.token) { socket.emit('token:refresh', j.token); cfg.token = j.token; } })
                .catch(function () {})
                .then(function () { if (connected) refreshTimer = setTimeout(loop, 20 * 60 * 1000); });
        }, 20 * 60 * 1000);
    }

    window.wsGlobal = {
        on: function (ev, cb) { socket.on(ev, cb); },
        off: function (ev, cb) { socket.off(ev, cb); },
        emit: function (ev, data) { if (connected) socket.emit(ev, data); },
        isConnected: function () { return connected; },
        joinClass: function (id) { if (connected) socket.emit('join:class', id); },
        joinConversation: function (id) { if (connected) socket.emit('join:conversation', id); },
    };
}());
