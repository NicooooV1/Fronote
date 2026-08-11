/**
 * Fronote WebSocket Server — Socket.IO avec auth JWT
 *
 * Channels (rooms cloisonnées par établissement — jamais de diffusion globale) :
 *   user:{userId}:{userType}        — Notifications personnelles (type inclus → anti-collision d'id)
 *   etab:{etabId}:user:{userId}     — Destinataire nominatif sûr par tenant
 *   etab:{etabId}                   — Diffusion à l'établissement
 *   etab:{etabId}:role:{role}       — Diffusion par rôle, bornée au tenant
 *   class:{classeId}                — Notifications de classe (jonction autorisée)
 *   conversation:{convId}           — Messages / typing (participants autorisés)
 *   admin:metrics[:{etabId}]        — Live admin dashboard (super_admin / par établissement)
 *
 * Endpoints HTTP internes (appeles par le PHP) :
 *   POST /notify/message       — Nouveau message
 *   POST /notify/notification  — Notification generale
 *   POST /notify/grade         — Nouvelle note
 *   POST /notify/absence       — Nouvelle absence
 *   POST /notify/event         — Evenement agenda
 *   POST /notify/badge         — Mise a jour badge sidebar
 *   GET  /health               — Sante du serveur
 *   GET  /metrics              — Statistiques admin
 *
 * Usage : node server.js
 * Config : .env (WEBSOCKET_PORT, WEBSOCKET_API_SECRET, JWT_SECRET, WSS_CERT_PATH, WSS_KEY_PATH)
 */

const path = require('path');
const fs = require('fs');

// Charger .env depuis la racine du projet
require('dotenv').config({ path: path.resolve(__dirname, '../.env') });

const http = require('http');
const https = require('https');
const { Server } = require('socket.io');
const jwt = require('jsonwebtoken');

const PORT = parseInt(process.env.WEBSOCKET_PORT || '3000', 10);
const API_SECRET = process.env.WEBSOCKET_API_SECRET;
// Endpoint PHP d'autorisation d'appartenance aux rooms (anti-IDOR). Si défini, une
// jonction join:class/join:conversation n'est acceptée qu'après validation par PHP.
const AUTHORIZE_URL = process.env.WS_PHP_AUTHORIZE_URL || '';
let __warnedNoAuthz = false;

/** Vérifie côté PHP que le socket a le droit de rejoindre (room_type, id). */
async function authorizeRoom(socket, type, id) {
    if (!AUTHORIZE_URL) {
        // Fail-CLOSED par DÉFAUT : sans endpoint d'autorisation, on REFUSE la jonction
        // (anti-IDOR cross-tenant non contournable). L'ancienne logique laissait passer
        // dès que NODE_ENV !== 'production' — or NODE_ENV est trivialement absent/mal
        // défini (cf. process PM2), ce qui rendait le contrôle fail-OPEN en pratique.
        // Le contournement de dev exige désormais un opt-in EXPLICITE et bruyant.
        const devBypass = process.env.WS_ALLOW_UNVERIFIED_JOINS === 'true';
        if (!devBypass) {
            if (!__warnedNoAuthz) {
                console.error('[SECURITY] WS_PHP_AUTHORIZE_URL non défini — jonctions de rooms REFUSÉES (fail-closed).');
                __warnedNoAuthz = true;
            }
            return false;
        }
        if (!__warnedNoAuthz) {
            console.warn('[WARN] WS_ALLOW_UNVERIFIED_JOINS=true — jonctions de rooms NON vérifiées (dev uniquement, JAMAIS en prod).');
            __warnedNoAuthz = true;
        }
        return true;
    }
    try {
        const controller = new AbortController();
        const t = setTimeout(() => controller.abort(), 4000);
        const resp = await fetch(AUTHORIZE_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WS-Secret': API_SECRET },
            body: JSON.stringify({ token: socket.rawToken, type, id }),
            signal: controller.signal,
        });
        clearTimeout(t);
        if (!resp.ok) return false;
        const data = await resp.json();
        return data && data.allow === true;
    } catch (e) {
        console.error('[ws authorize] failed:', e.message);
        return false; // fail-closed : en cas d'erreur, on refuse la jonction
    }
}
const JWT_SECRET = process.env.JWT_SECRET || process.env.APP_KEY;

// Fail closed: never run with a guessable secret. No hard-coded fallbacks.
if (!API_SECRET) {
    console.error('[FATAL] WEBSOCKET_API_SECRET is not set. Refusing to start with a default secret.');
    process.exit(1);
}
if (!JWT_SECRET) {
    console.error('[FATAL] JWT_SECRET (or APP_KEY) is not set. Refusing to start with a default secret.');
    process.exit(1);
}

// Origines CORS autorisées — liste séparée par virgules dans WEBSOCKET_ALLOWED_ORIGINS.
// On ne retombe JAMAIS sur '*', quel que soit NODE_ENV : un caractère générique laisserait
// n'importe quel site ouvrir une poignée de main. Si la liste explicite est absente, on
// dérive un défaut SÛR depuis l'origine de l'application (APP_URL / WEBSOCKET_CLIENT_URL) ;
// à défaut on VERROUILLE (aucune origine cross-site) plutôt que d'ouvrir à tous.
function originOf(url) {
    try { return new URL(url).origin; } catch (e) { return null; }
}
const ALLOWED_ORIGINS = process.env.WEBSOCKET_ALLOWED_ORIGINS
    ? process.env.WEBSOCKET_ALLOWED_ORIGINS.split(',').map((o) => o.trim()).filter(Boolean)
    : null;

const DEFAULT_ORIGINS = [process.env.APP_URL, process.env.WEBSOCKET_CLIENT_URL]
    .map(originOf)
    .filter(Boolean);

// Jamais de '*' : liste explicite, sinon défaut dérivé de l'app, sinon liste vide (verrou).
const corsOrigin = ALLOWED_ORIGINS || (DEFAULT_ORIGINS.length ? DEFAULT_ORIGINS : []);
// La liste effective sert aussi au reflet d'origine des routes HTTP (handleHttp).
const EFFECTIVE_ORIGINS = ALLOWED_ORIGINS || DEFAULT_ORIGINS;
if (!ALLOWED_ORIGINS && !DEFAULT_ORIGINS.length) {
    console.warn('[ws] WEBSOCKET_ALLOWED_ORIGINS/APP_URL non définis : CORS verrouillé (aucune origine cross-site).');
}

/**
 * Extract the shared API secret from an inbound /notify or /metrics request.
 * Accepts both `Authorization: Bearer <secret>` (what the PHP client sends)
 * and the legacy `X-Api-Secret` header.
 */
function extractApiSecret(req) {
    const auth = req.headers['authorization'] || '';
    if (auth.startsWith('Bearer ')) {
        return auth.slice(7).trim();
    }
    return req.headers['x-api-secret'] || '';
}

/** Constant-time-ish compare to avoid trivially leaking the secret length/prefix. */
function secretMatches(provided) {
    if (typeof provided !== 'string' || provided.length !== API_SECRET.length) {
        return false;
    }
    let diff = 0;
    for (let i = 0; i < provided.length; i++) {
        diff |= provided.charCodeAt(i) ^ API_SECRET.charCodeAt(i);
    }
    return diff === 0;
}

// ─── TLS/WSS Support ───────────────────────────────────────────
const certPath = process.env.WSS_CERT_PATH;
const keyPath = process.env.WSS_KEY_PATH;
let server;

if (certPath && keyPath && fs.existsSync(certPath) && fs.existsSync(keyPath)) {
    server = https.createServer({
        cert: fs.readFileSync(certPath),
        key: fs.readFileSync(keyPath),
    }, handleHttp);
    console.log('[WSS] TLS enabled');
} else {
    server = http.createServer(handleHttp);
}

// ─── Rate limiting ─────────────────────────────────────────────
const rateLimits = new Map(); // socketId -> { count, resetAt }
const RATE_LIMIT_MAX = 30; // events per minute
const RATE_LIMIT_WINDOW = 60000;

function checkRateLimit(socketId) {
    const now = Date.now();
    let entry = rateLimits.get(socketId);
    if (!entry || now > entry.resetAt) {
        entry = { count: 0, resetAt: now + RATE_LIMIT_WINDOW };
        rateLimits.set(socketId, entry);
    }
    entry.count++;
    return entry.count <= RATE_LIMIT_MAX;
}

// Clean expired rate limit entries periodically
setInterval(() => {
    const now = Date.now();
    for (const [id, entry] of rateLimits) {
        if (now > entry.resetAt) rateLimits.delete(id);
    }
}, 30000);

// ─── Plafond de connexions concurrentes (anti-abus / anti-DoS) ──
// Un même utilisateur (userType:userId) ou une même IP ne peut ouvrir qu'un nombre
// borné de sockets simultanées. Au-delà, la nouvelle connexion est refusée.
const MAX_CONN_PER_USER = parseInt(process.env.WS_MAX_CONN_PER_USER || '5', 10);
const MAX_CONN_PER_IP = parseInt(process.env.WS_MAX_CONN_PER_IP || '20', 10);
const connByUser = new Map(); // 'userType:userId' -> count
const connByIp = new Map();   // ip -> count

function incrCount(map, key) {
    const n = (map.get(key) || 0) + 1;
    map.set(key, n);
    return n;
}
function decrCount(map, key) {
    const n = (map.get(key) || 0) - 1;
    if (n <= 0) map.delete(key);
    else map.set(key, n);
}

// ─── Metrics ───────────────────────────────────────────────────
const metrics = {
    totalConnections: 0,
    totalEvents: 0,
    totalNotifications: 0,
    startTime: Date.now(),
    eventsPerSecond: 0,
    connectionLog: [], // last 50 connections
};

let eventsThisSecond = 0;
setInterval(() => {
    metrics.eventsPerSecond = eventsThisSecond;
    eventsThisSecond = 0;
    // Emit metrics to admin rooms : room globale (super_admin) + rooms
    // cloisonnées par établissement ('admin:metrics:<etab>'). Les métriques
    // restent globales au serveur ; seul le ciblage des destinataires diffère.
    const metricsPayload = {
        connections: io.engine.clientsCount,
        eventsPerSecond: metrics.eventsPerSecond,
        totalEvents: metrics.totalEvents,
        uptime: Math.floor((Date.now() - metrics.startTime) / 1000),
    };
    for (const roomName of io.sockets.adapter.rooms.keys()) {
        if (roomName === 'admin:metrics' || roomName.startsWith('admin:metrics:')) {
            io.to(roomName).emit('metrics', metricsPayload);
        }
    }
}, 1000);

// ─── HTTP Handler ──────────────────────────────────────────────
function handleHttp(req, res) {
    // CORS — only reflect explicitly whitelisted origins. The /notify and /metrics
    // routes are server-to-server (secret-gated) and need no permissive CORS.
    const origin = req.headers['origin'];
    if (origin && EFFECTIVE_ORIGINS.includes(origin)) {
        res.setHeader('Access-Control-Allow-Origin', origin);
        res.setHeader('Vary', 'Origin');
    }
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type, X-Api-Secret, Authorization');

    if (req.method === 'OPTIONS') {
        res.writeHead(204);
        res.end();
        return;
    }

    // Health endpoint
    if (req.method === 'GET' && req.url === '/health') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({
            status: 'ok',
            uptime: Math.floor(process.uptime()),
            connections: io.engine.clientsCount,
            memory: Math.round(process.memoryUsage().heapUsed / 1024 / 1024),
        }));
        return;
    }

    // Metrics endpoint (admin only, requires API secret)
    if (req.method === 'GET' && req.url === '/metrics') {
        if (!secretMatches(extractApiSecret(req))) {
            res.writeHead(403);
            res.end('Forbidden');
            return;
        }
        res.writeHead(200, { 'Content-Type': 'application/json' });

        // Room breakdown
        const rooms = {};
        for (const [roomName, sockets] of io.sockets.adapter.rooms) {
            if (!roomName.includes(':')) continue;
            rooms[roomName] = sockets.size;
        }

        res.end(JSON.stringify({
            connections: io.engine.clientsCount,
            totalConnections: metrics.totalConnections,
            totalEvents: metrics.totalEvents,
            totalNotifications: metrics.totalNotifications,
            eventsPerSecond: metrics.eventsPerSecond,
            uptime: Math.floor(process.uptime()),
            memory: Math.round(process.memoryUsage().heapUsed / 1024 / 1024),
            rooms,
            recentConnections: metrics.connectionLog.slice(-20),
        }));
        return;
    }

    // Notification endpoints (called by PHP)
    if (req.method === 'POST' && req.url.startsWith('/notify/')) {
        if (!secretMatches(extractApiSecret(req))) {
            res.writeHead(403, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ error: 'Forbidden' }));
            return;
        }

        let body = '';
        req.on('data', (chunk) => body += chunk);
        req.on('end', () => {
            try {
                const data = JSON.parse(body);
                const channel = req.url.replace('/notify/', '');
                handleNotification(channel, data);
                metrics.totalNotifications++;
                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ success: true }));
            } catch (e) {
                res.writeHead(400, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ error: 'Invalid JSON' }));
            }
        });
        return;
    }

    res.writeHead(404);
    res.end('Not found');
}

// ─── Socket.IO ──────────────────────────────────────────────────
const io = new Server(server, {
    cors: {
        origin: corsOrigin,
        methods: ['GET', 'POST'],
    },
    pingTimeout: 60000,
    pingInterval: 25000,
    maxHttpBufferSize: 1e6, // 1MB max payload
});

// Auth middleware — verify JWT
io.use((socket, next) => {
    const token = socket.handshake.auth?.token || socket.handshake.query?.token;
    if (!token) {
        return next(new Error('Authentication required'));
    }

    try {
        const decoded = jwt.verify(token, JWT_SECRET);
        // PHP WebSocket::generateToken() emits `userId`/`userType` (plus `sub`).
        // Keep the legacy claim names as fallbacks for forward/backward compat.
        socket.userId = decoded.userId || decoded.sub || decoded.user_id;
        socket.userType = decoded.userType || decoded.role || decoded.user_type || '';
        socket.userName = decoded.name || '';
        socket.tokenExp = decoded.exp || 0;
        socket.etab = decoded.etablissement_id || 0;   // cloisonnement tenant des rooms
        socket.rawToken = token;                        // pour l'autorisation d'appartenance PHP
        next();
    } catch (e) {
        next(new Error('Invalid token'));
    }
});

// Connection handler
io.on('connection', (socket) => {
    const { userId, userType } = socket;
    const etab = socket.etab || 0;
    const ip = socket.handshake.address;
    const userKey = `${userType}:${userId}`;

    // ─── Plafond de connexions concurrentes (par utilisateur / par IP) ───
    // Refuser AVANT de rejoindre la moindre room et d'incrémenter les compteurs.
    if ((connByUser.get(userKey) || 0) >= MAX_CONN_PER_USER
        || (connByIp.get(ip) || 0) >= MAX_CONN_PER_IP) {
        console.log(`[!] ${userType}#${userId} (${ip}) refusé — plafond de connexions atteint`);
        socket.emit('error', { message: 'Too many concurrent connections' });
        socket.disconnect(true);
        return;
    }
    incrCount(connByUser, userKey);
    incrCount(connByIp, ip);

    // ─── Rooms cloisonnées par tenant (dérivées des claims JWT VÉRIFIÉS) ───
    // Jamais de room globale : le ciblage passe par des rooms nominatives et par
    // établissement, ce qui empêche toute diffusion cross-tenant (finding CRIT).
    socket.join(`user:${userId}:${userType}`);   // destinataire nominatif (type inclus → anti-collision d'id)
    if (etab > 0) {
        socket.join(`etab:${etab}`);                       // diffusion à l'établissement
        socket.join(`etab:${etab}:user:${userId}`);        // destinataire nominatif sûr par tenant
        socket.join(`etab:${etab}:role:${userType}`);      // diffusion par rôle, bornée au tenant
    }

    // ─── Expiration JWT : déconnexion planifiée à l'échéance du jeton ───
    let expiryTimer = null;
    function scheduleExpiry() {
        if (expiryTimer) { clearTimeout(expiryTimer); expiryTimer = null; }
        const ms = (Number(socket.tokenExp) * 1000) - Date.now();
        if (!socket.tokenExp || ms <= 0) {
            // Jeton déjà expiré / sans échéance : fermer immédiatement (fail-closed).
            socket.emit('token:error', 'Token expired');
            socket.disconnect(true);
            return;
        }
        // setTimeout est borné à ~24,8 jours ; nos TTL sont bien inférieurs.
        expiryTimer = setTimeout(() => {
            console.log(`[!] ${userType}#${userId} jeton expiré — déconnexion`);
            socket.emit('token:error', 'Token expired');
            socket.disconnect(true);
        }, ms);
    }
    scheduleExpiry();

    metrics.totalConnections++;
    metrics.connectionLog.push({
        userId, userType,
        action: 'connect',
        ip: socket.handshake.address,
        at: new Date().toISOString(),
    });
    if (metrics.connectionLog.length > 50) metrics.connectionLog.shift();

    console.log(`[+] ${userType}#${userId} connected (${socket.id})`);

    // ─── Heartbeat ──────────────────────────────────────────
    let lastHeartbeat = Date.now();
    const heartbeatCheck = setInterval(() => {
        if (Date.now() - lastHeartbeat > 90000) {
            console.log(`[!] ${userType}#${userId} heartbeat timeout`);
            socket.disconnect(true);
        }
    }, 30000);

    socket.on('heartbeat', () => {
        lastHeartbeat = Date.now();
        socket.emit('heartbeat:ack');
    });

    // ─── Token refresh ──────────────────────────────────────
    // Le nouveau jeton doit être valide ET appartenir au MÊME utilisateur (même
    // userId + userType + établissement) que le socket : on n'accepte pas qu'un
    // socket « change d'identité » via un refresh. Sinon on refuse et on ne
    // prolonge pas l'échéance.
    socket.on('token:refresh', (newToken) => {
        try {
            const decoded = jwt.verify(newToken, JWT_SECRET);
            const newId = decoded.userId || decoded.sub || decoded.user_id;
            const newType = decoded.userType || decoded.role || decoded.user_type || '';
            const newEtab = decoded.etablissement_id || 0;
            if (String(newId) !== String(userId)
                || newType !== userType
                || (etab > 0 && Number(newEtab) !== Number(etab))) {
                socket.emit('token:error', 'Token identity mismatch');
                return;
            }
            socket.tokenExp = decoded.exp || 0;
            socket.rawToken = newToken;
            scheduleExpiry();               // reprogrammer la déconnexion sur la nouvelle échéance
            socket.emit('token:refreshed');
        } catch (e) {
            socket.emit('token:error', 'Invalid refresh token');
        }
    });

    // ─── Join class (rate limit + autorisation d'appartenance) ───
    socket.on('join:class', async (classeId) => {
        if (!checkRateLimit(socket.id)) return;
        if (!classeId) return;
        if (!(await authorizeRoom(socket, 'class', classeId))) {
            socket.emit('join:denied', { room: 'class', id: classeId });
            return;
        }
        socket.join(`class:${classeId}`);
    });

    // ─── Join conversation (rate limit + autorisation d'appartenance) ───
    socket.on('join:conversation', async (convId) => {
        if (!checkRateLimit(socket.id)) return;
        if (!convId) return;
        if (!(await authorizeRoom(socket, 'conversation', convId))) {
            socket.emit('join:denied', { room: 'conversation', id: convId });
            return;
        }
        socket.join(`conversation:${convId}`);
    });

    // ─── Typing indicator ───────────────────────────────────
    // Relais FIDÈLE au contrat partagé : on ne strippe PAS isTyping / userName /
    // userType. userName provient du claim JWT vérifié (socket.userName), jamais
    // d'une valeur fournie par le client (anti-usurpation d'identité d'affichage).
    socket.on('typing', (data) => {
        if (!checkRateLimit(socket.id)) return;
        metrics.totalEvents++;
        eventsThisSecond++;
        if (data && data.conversationId) {
            socket.to(`conversation:${data.conversationId}`).emit('typing', {
                conversationId: data.conversationId,
                userId,
                userType,
                userName: socket.userName || '',
                isTyping: data.isTyping === true || data.isTyping === 'true',
            });
        }
    });

    // ─── Admin metrics room ─────────────────────────────────
    // Cloisonnement tenant : un administrateur n'accède qu'aux métriques
    // de son établissement (room 'admin:metrics:<etab>'). Seul un super_admin
    // rejoint la room globale 'admin:metrics'. Un administrateur sans claim
    // établissement est refusé (empêche l'accès cross-tenant).
    socket.on('join:admin', () => {
        if (userType === 'super_admin') {
            socket.join('admin:metrics');
        } else if (userType === 'administrateur' && socket.etab) {
            socket.join(`admin:metrics:${socket.etab}`);
        }
    });

    // ─── Generic event tracking ─────────────────────────────
    socket.onAny((eventName) => {
        metrics.totalEvents++;
        eventsThisSecond++;
        if (!checkRateLimit(socket.id)) {
            console.log(`[!] Rate limit exceeded for ${userType}#${userId}`);
            socket.emit('error', { message: 'Rate limit exceeded' });
            socket.disconnect(true);
        }
    });

    socket.on('disconnect', () => {
        clearInterval(heartbeatCheck);
        if (expiryTimer) { clearTimeout(expiryTimer); expiryTimer = null; }
        decrCount(connByUser, userKey);
        decrCount(connByIp, ip);
        rateLimits.delete(socket.id);
        metrics.connectionLog.push({
            userId, userType,
            action: 'disconnect',
            at: new Date().toISOString(),
        });
        if (metrics.connectionLog.length > 50) metrics.connectionLog.shift();
        console.log(`[-] ${userType}#${userId} disconnected`);
    });
});

// ─── Notification dispatcher ────────────────────────────────────
// Cloisonnement STRICT (fail-closed) : on n'émet QUE vers des rooms résolues à
// partir du routage explicite fourni par le PHP (etablissement_id + userId/convId/
// eleveId/role/class…). Il n'y a PLUS de diffusion globale io.emit() : sans room
// résolue, la notification est ABANDONNÉE (jamais de fuite cross-tenant).
function handleNotification(channel, data) {
    const etab = parseInt(data.etablissement_id, 10) || 0;

    // ─── Événements TEMPS RÉEL cloisonnés à une conversation (contrat partagé) ───
    // Diffusés UNIQUEMENT à la room 'conversation:<id>' (participants autorisés,
    // fail-closed : sans convId résolu → rien n'est émis). Formes de payload
    // strictement alignées sur le contrat client (conversation.js / MsgRealtime).
    const convId = data.conversationId || data.convId || null;

    if (channel === 'message:updated' || channel === 'message-updated') {
        if (!convId) {
            console.warn("[ws] 'message:updated' abandonné : conversationId manquant.");
            return;
        }
        io.to(`conversation:${convId}`).emit('message:updated', {
            conversationId: convId,
            messageId: data.messageId,
            kind: data.kind,                 // 'edit' | 'delete' | 'reaction' | 'pin'
            data: data.data || {},
        });
        return;
    }

    if (channel === 'read') {
        if (!convId) {
            console.warn("[ws] 'read' abandonné : conversationId manquant.");
            return;
        }
        io.to(`conversation:${convId}`).emit('read', {
            conversationId: convId,
            userId: data.userId,
            userType: data.userType,
            lastReadMessageId: data.lastReadMessageId,
        });
        return;
    }

    const payload = {
        type: channel,
        ...data,
        // Normalise : le contrat client lit toujours `conversationId`.
        conversationId: convId || undefined,
        timestamp: new Date().toISOString(),
    };

    const rooms = new Set();

    // Aide : cible un utilisateur nominatif de façon sûre par tenant.
    // Room typée si le type est connu (anti-collision d'id entre types) ; sinon
    // room nominative bornée à l'établissement (jamais d'id nu, non cloisonné).
    const addUser = (uid, utype) => {
        if (!uid) return;
        if (utype) rooms.add(`user:${uid}:${utype}`);
        else if (etab > 0) rooms.add(`etab:${etab}:user:${uid}`);
    };

    // 1) Cibles explicites (tableau targets) — chacune typée.
    if (Array.isArray(data.targets)) {
        data.targets.forEach((t) => addUser(t.user_id, t.user_type));
    }

    // 2) Destinataire nominatif (notifyUser). recipientType optionnel.
    if (data.userId) addUser(data.userId, data.recipientType);

    // 3) Élève ciblé (note / absence) : destinataire de type 'eleve'.
    if (data.eleveId) addUser(data.eleveId, 'eleve');

    // 4) Conversation (message) : participants ayant rejoint la room autorisée.
    //    (accepte conversationId ou convId — cf. normalisation ci-dessus)
    if (convId) rooms.add(`conversation:${convId}`);

    // 5) Événement agenda : routage selon la cible.
    if (channel === 'event') {
        if (data.targetType === 'user') addUser(data.targetId, data.recipientType);
        else if (data.targetType === 'class' && data.targetId) rooms.add(`class:${data.targetId}`);
        else if (etab > 0) rooms.add(`etab:${etab}`); // 'all' → tout l'établissement (jamais tous les tenants)
    }

    // 6) Diffusion par rôle / classe — TOUJOURS bornée à l'établissement.
    if (data.role && etab > 0) rooms.add(`etab:${etab}:role:${data.role}`);
    if (data.classe_id) rooms.add(`class:${data.classe_id}`);

    if (rooms.size === 0) {
        // Fail-closed : aucun ciblage résolu → on n'émet rien (pas de io.emit global).
        console.warn(`[ws] notification '${channel}' abandonnée : aucun destinataire résolu (etab=${etab}).`);
        return;
    }
    io.to([...rooms]).emit(channel, payload);
}

// ─── Start ──────────────────────────────────────────────────────
server.listen(PORT, () => {
    const proto = certPath ? 'wss' : 'ws';
    console.log(`Fronote WebSocket server running on ${proto}://localhost:${PORT}`);
    console.log(`Health check: http://localhost:${PORT}/health`);
    console.log(`Metrics: http://localhost:${PORT}/metrics`);
});
