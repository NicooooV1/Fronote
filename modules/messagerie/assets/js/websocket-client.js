/**
 * Adaptateur temps réel Messagerie — Fronote
 * ------------------------------------------------------------------
 * Il n'y a QU'UNE SEULE socket dans l'application : celle ouverte par
 * assets/js/ws-global.js (window.wsGlobal). Ce fichier n'ouvre PLUS de
 * seconde connexion (l'ancien client émettait des events camelCase que le
 * serveur n'écoute pas — 'joinConversation'/'joinUser' — et écoutait
 * 'newMessage'/'messageRead' que le serveur n'émet pas : tout retombait en
 * polling). Il expose désormais une API stable au-dessus de la socket unique :
 *
 *   window.MsgRealtime = {
 *     join(convId),                 // socket.emit('join:conversation', {conversationId})
 *     leave(convId),
 *     on(event, cb), off(event, cb),
 *     emit(event, data),
 *     emitTyping(convId, isTyping),  // socket.emit('typing', {conversationId, isTyping})
 *     isConnected()
 *   }
 *
 * Événements serveur → client (room 'conversation:<id>') :
 *   'message'          {conversationId, message}
 *   'message:updated'  {conversationId, messageId, kind, data}
 *   'typing'           {conversationId, userId, userType, userName, isTyping}
 *   'read'             {conversationId, userId, userType, lastReadMessageId}
 *
 * window.wsClient reste défini en alias de compatibilité (anciennes références),
 * mais délègue lui aussi à la socket globale — aucune seconde socket.
 */
(function () {
    'use strict';

    // Mémorise les rooms voulues + les écouteurs pour les (ré)appliquer à chaque
    // (re)connexion : un join émis avant l'ouverture de la socket serait perdu.
    var joined = Object.create(null);   // convId -> true
    var listeners = [];                 // { event, cb }
    var reconnectWired = false;

    /** Résolution PARESSEUSE de la socket globale (l'ordre de chargement des
     *  <script defer> peut faire s'exécuter ce fichier avant ws-global.js ;
     *  au moment des appels — DOMContentLoaded/plus tard — wsGlobal existe). */
    function g() { return window.wsGlobal || null; }

    function doJoin(convId) {
        var s = g();
        if (s && typeof s.joinConversation === 'function') {
            s.joinConversation(convId);
        }
    }

    /** Rebranche joins + écouteurs à chaque 'connect' (reconnexion incluse). */
    function wireReconnect() {
        if (reconnectWired) return;
        var s = g();
        if (!s || typeof s.on !== 'function') return;
        s.on('connect', function () {
            Object.keys(joined).forEach(doJoin);
        });
        reconnectWired = true;
    }

    var MsgRealtime = {
        /** Rejoint la room de la conversation (idempotent, re-joué à la reconnexion). */
        join: function (convId) {
            if (!convId && convId !== 0) return;
            var key = String(convId);
            joined[key] = true;
            // Conversation actuellement AFFICHÉE : ws-global s'en sert pour ne PAS
            // notifier/sonner un message de la conversation déjà à l'écran.
            window.__activeConversationId = key;
            wireReconnect();
            doJoin(convId);   // si déjà connecté → rejoint tout de suite ; sinon 'connect' le fera
        },

        leave: function (convId) {
            var key = String(convId);
            delete joined[key];
            if (window.__activeConversationId === key) {
                window.__activeConversationId = null;
            }
        },

        on: function (event, cb) {
            listeners.push({ event: event, cb: cb });
            var s = g();
            if (s && typeof s.on === 'function') s.on(event, cb);
        },

        off: function (event, cb) {
            var s = g();
            if (s && typeof s.off === 'function') s.off(event, cb);
        },

        emit: function (event, data) {
            var s = g();
            if (s && typeof s.emit === 'function') s.emit(event, data);
        },

        /** Relaie l'état de frappe (le serveur renvoie userName depuis le JWT). */
        emitTyping: function (convId, isTyping) {
            var s = g();
            if (s && typeof s.emit === 'function') {
                s.emit('typing', { conversationId: convId, isTyping: isTyping === true });
            }
        },

        isConnected: function () {
            var s = g();
            return !!(s && typeof s.isConnected === 'function' && s.isConnected());
        },
    };

    window.MsgRealtime = MsgRealtime;

    // ─── Alias de compatibilité (ancienne API window.wsClient) ─────────────
    // Ne crée AUCUNE socket : tout est délégué à la socket globale unique.
    window.wsClient = {
        init: function () { /* no-op : socket unique gérée par ws-global.js */ },
        joinConversation: function (convId) { MsgRealtime.join(convId); },
        leaveConversation: function (convId) { MsgRealtime.leave(convId); },
        joinClass: function (classeId) {
            var s = g();
            if (s && typeof s.joinClass === 'function') s.joinClass(classeId);
        },
        on: function (event, cb) { MsgRealtime.on(event, cb); },
        off: function (event, cb) { MsgRealtime.off(event, cb); },
        emit: function (event, data) { MsgRealtime.emit(event, data); },
        get connected() { return MsgRealtime.isConnected(); },
    };
}());
