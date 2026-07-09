/**
 * Fronote WebSocket Server — Socket.IO avec auth JWT
 *
 * Channels :
 *   user:{userId}:{userType}  — Notifications personnelles
 *   class:{classeId}           — Notifications de classe
 *   role:{role}                — Notifications par role
 *   conversation:{convId}      — Messages / typing
 *   admin:metrics              — Live admin dashboard
 *   broadcast                  — Notifications globales
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

// Allowed CORS origins — comma-separated env. When unset, falls back to '*'.
// This is acceptable here because the Socket.IO handshake is authenticated by a
// JWT in the handshake payload (not by ambient cookies), and `credentials` is
// never enabled — so a permissive origin cannot be leveraged for CSRF-style
// cookie replay. Set WEBSOCKET_ALLOWED_ORIGINS in production to lock it down.
const ALLOWED_ORIGINS = process.env.WEBSOCKET_ALLOWED_ORIGINS
    ? process.env.WEBSOCKET_ALLOWED_ORIGINS.split(',').map((o) => o.trim()).filter(Boolean)
    : null;

// Securite : en production, ne JAMAIS retomber sur '*'. Sans liste d'origines explicite, on
// verrouille (aucune origine cross-site) plutot que d'ouvrir a tous.
const corsOrigin = ALLOWED_ORIGINS || (process.env.NODE_ENV === 'production' ? [] : '*');
if (!ALLOWED_ORIGINS && process.env.NODE_ENV === 'production') {
    console.warn('[ws] WEBSOCKET_ALLOWED_ORIGINS non défini en production : CORS verrouillé.');
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
    // Emit metrics to admin room
    io.to('admin:metrics').emit('metrics', {
        connections: io.engine.clientsCount,
        eventsPerSecond: metrics.eventsPerSecond,
        totalEvents: metrics.totalEvents,
        uptime: Math.floor((Date.now() - metrics.startTime) / 1000),
    });
}, 1000);

// ─── HTTP Handler ──────────────────────────────────────────────
function handleHttp(req, res) {
    // CORS — only reflect explicitly whitelisted origins. The /notify and /metrics
    // routes are server-to-server (secret-gated) and need no permissive CORS.
    const origin = req.headers['origin'];
    if (ALLOWED_ORIGINS && origin && ALLOWED_ORIGINS.includes(origin)) {
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
    const userRoom = `user:${userId}:${userType}`;

    // Join personal rooms
    socket.join(userRoom);
    socket.join(`role:${userType}`);

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
    socket.on('token:refresh', (newToken) => {
        try {
            const decoded = jwt.verify(newToken, JWT_SECRET);
            socket.tokenExp = decoded.exp || 0;
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
    socket.on('typing', (data) => {
        if (!checkRateLimit(socket.id)) return;
        metrics.totalEvents++;
        eventsThisSecond++;
        if (data.conversationId) {
            socket.to(`conversation:${data.conversationId}`).emit('typing', {
                userId, userType, conversationId: data.conversationId,
            });
        }
    });

    // ─── Admin metrics room ─────────────────────────────────
    socket.on('join:admin', () => {
        if (userType === 'administrateur') {
            socket.join('admin:metrics');
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
function handleNotification(channel, data) {
    const targets = data.targets || [];
    const payload = {
        type: channel,
        ...data,
        timestamp: new Date().toISOString(),
    };

    // Targeted send
    if (targets.length > 0) {
        targets.forEach((t) => {
            const room = `user:${t.user_id}:${t.user_type}`;
            io.to(room).emit(channel, payload);
        });
        return;
    }

    // By role
    if (data.role) {
        io.to(`role:${data.role}`).emit(channel, payload);
        return;
    }

    // By class
    if (data.classe_id) {
        io.to(`class:${data.classe_id}`).emit(channel, payload);
        return;
    }

    // Global broadcast
    io.emit(channel, payload);
}

// ─── Start ──────────────────────────────────────────────────────
server.listen(PORT, () => {
    const proto = certPath ? 'wss' : 'ws';
    console.log(`Fronote WebSocket server running on ${proto}://localhost:${PORT}`);
    console.log(`Health check: http://localhost:${PORT}/health`);
    console.log(`Metrics: http://localhost:${PORT}/metrics`);
});
