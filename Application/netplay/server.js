/**
 * Vaultcast — EmulatorJS Socket.IO netplay relay (frame-gated protocol 4).
 * Bound to loopback; nginx forwards /netplay/ to this service.
 * Client and relay must be deployed together. No automatic host migration.
 */
'use strict';

const express = require('express');
const http = require('http');
const socketIo = require('socket.io');
const cors = require('cors');
const fs = require('fs');
const path = require('path');

const app = express();
const server = http.createServer(app);

app.use(cors({
  origin: '*',
  methods: ['GET', 'POST'],
  allowedHeaders: ['Content-Type'],
  credentials: false,
}));

const io = socketIo(server, {
  cors: {
    origin: '*',
    methods: ['GET', 'POST'],
    credentials: false,
  },
  path: '/socket.io',
  // savestate transfer on guest join can exceed default 1MB buffer
  maxHttpBufferSize: 20e6,
});

const PORT = Number(process.env.PORT || 3100);
const HOST = process.env.HOST || '127.0.0.1';
const LOG_FILE = process.env.NETPLAY_LOG || path.join(__dirname, 'netplay.log');
let rooms = Object.create(null);

function npLog(event, ctx) {
  const row = Object.assign({ ts: new Date().toISOString(), event }, ctx || {});
  let line;
  try { line = JSON.stringify(row); } catch (e) { line = JSON.stringify({ ts: row.ts, event, error: 'stringify' }); }
  console.log('[netplay-json]', line);
  try { fs.appendFileSync(LOG_FILE, line + '\n'); } catch (eW) {}
}

function payloadMeta(data) {
  if (data == null) return { kind: 'null', bytes: 0 };
  if (typeof data === 'string') return { kind: 'string', bytes: data.length };
  if (Buffer.isBuffer(data)) return { kind: 'buffer', bytes: data.length };
  if (Array.isArray(data)) return { kind: 'array', bytes: data.length, sample0: typeof data[0] };
  if (typeof data === 'object') {
    const keys = Object.keys(data);
    let bytes = 0;
    let stateKind = null;
    if (data.state != null) {
      if (Buffer.isBuffer(data.state)) { bytes = data.state.length; stateKind = 'buffer'; }
      else if (Array.isArray(data.state)) { bytes = data.state.length; stateKind = 'array'; }
      else if (typeof data.state === 'string') { bytes = data.state.length; stateKind = 'string'; }
      else if (data.state && typeof data.state.length === 'number') { bytes = data.state.length; stateKind = typeof data.state; }
    }
    return { kind: 'object', keys, stateBytes: bytes, stateKind, stateEncoding: data.stateEncoding || null, ready: !!data.ready };
  }
  return { kind: typeof data, bytes: 0 };
}

const getClientIp = (socket) => {
  const forwarded = socket.handshake.headers['x-forwarded-for'];
  if (forwarded) return forwarded.split(',')[0].trim();
  return socket.handshake.headers['x-real-ip'] || socket.handshake.address;
};

setInterval(() => {
  for (const sessionId in rooms) {
    if (Object.keys(rooms[sessionId].players).length === 0) {
      delete rooms[sessionId];
      npLog('room_gc', { sessionId });
    }
  }
}, 60000);

app.get('/', (_req, res) => {
  res.type('text').send('Vaultcast EmulatorJS netplay OK\n');
});

app.get('/health', (_req, res) => {
  res.json({
    ok: true,
    service: 'vaultcast-netplay',
    version: 'vaultcast-lockstep-4',
    rooms: Object.keys(rooms).length,
    roomIds: Object.keys(rooms),
  });
});

app.get('/list', (req, res) => {
  const gameId = req.query.game_id;
  const openRooms = Object.keys(rooms)
    .filter((sessionId) => {
      const room = rooms[sessionId];
      return (
        room &&
        Object.keys(room.players).length < room.maxPlayers &&
        String(room.gameId) === String(gameId)
      );
    })
    .reduce((acc, sessionId) => {
      const room = rooms[sessionId];
      const ownerPlayerId = Object.keys(room.players).find(
        (playerId) => room.players[playerId].socketId === room.owner
      );
      const playerName = ownerPlayerId ? room.players[ownerPlayerId].player_name : 'Unknown';
      acc[sessionId] = {
        room_name: room.roomName,
        current: Object.keys(room.players).length,
        max: room.maxPlayers,
        player_name: playerName,
        hasPassword: !!room.password,
      };
      return acc;
    }, {});
  res.json(openRooms);
});

io.on('connection', (socket) => {
  const ip = getClientIp(socket);
  npLog('connect', { socketId: socket.id, ip });

  socket.on('open-room', (data, callback) => {
    let sessionId, playerId, roomName, gameId, maxPlayers, playerName;
    try {
      if (!data || !data.extra || data.extra.netplay_protocol !== 'vaultcast-lockstep-4') {
        return typeof callback === 'function' && callback('Refresh the app: incompatible netplay client');
      }
      if (data && data.extra) {
        sessionId = data.extra.sessionid;
        playerId = data.extra.userid || data.extra.playerId;
        roomName = data.extra.room_name;
        gameId = data.extra.game_id;
        maxPlayers = data.maxPlayers || 4;
        playerName = data.extra.player_name || 'Unknown';
      }
      if (!sessionId || !playerId) {
        npLog('open-room_fail', { socketId: socket.id, error: 'invalid_data' });
        return typeof callback === 'function' && callback('Invalid data: sessionId and playerId required');
      }
      if (socket.sessionId) return typeof callback === 'function' && callback('Already in a room');
      maxPlayers = Math.max(2, Math.min(4, Number(maxPlayers) || 2));
      if (rooms[sessionId]) {
        const existing = rooms[sessionId];
        if (Object.keys(existing.players).length === 0) {
          delete rooms[sessionId];
        } else {
          npLog('open-room_fail', { sessionId, error: 'exists', players: Object.keys(existing.players).length });
          return typeof callback === 'function' && callback('Room already exists');
        }
      }

      let finalDomain = data.extra && data.extra.domain;
      if (finalDomain === undefined || finalDomain === null) finalDomain = 'unknown';

      rooms[sessionId] = {
        owner: socket.id,
        players: { [playerId]: { ...data.extra, socketId: socket.id, player_name: playerName, player_slot: 1 } },
        peers: [],
        roomName: roomName || `Room ${sessionId}`,
        gameId: gameId || 'default',
        domain: finalDomain,
        password: (data && data.password) || null,
        maxPlayers: maxPlayers,
        openedAt: Date.now(),
      };
      socket.join(sessionId);
      socket.sessionId = sessionId;
      socket.playerId = playerId;
      io.to(sessionId).emit('users-updated', rooms[sessionId].players);
      npLog('open-room', {
        sessionId,
        playerId,
        playerName,
        gameId,
        maxPlayers,
        socketId: socket.id,
        domain: finalDomain,
      });
      if (typeof callback === 'function') callback(null);
    } catch (e) {
      npLog('open-room_error', { socketId: socket.id, error: String(e && e.message ? e.message : e) });
      if (typeof callback === 'function') callback('open-room error');
    }
  });

  socket.on('join-room', (data, callback) => {
    try {
      const extra = (data && data.extra) || {};
      if (extra.netplay_protocol !== 'vaultcast-lockstep-4') {
        return typeof callback === 'function' && callback('Refresh the app: incompatible netplay client');
      }
      const sessionId = extra.sessionid;
      const playerId = extra.userid;
      if (!sessionId || !playerId) {
        npLog('join-room_fail', { socketId: socket.id, error: 'invalid_data' });
        if (typeof callback === 'function') callback('Invalid data: sessionId and playerId required');
        return;
      }
      const room = rooms[sessionId];
      if (!room) {
        npLog('join-room_fail', { sessionId, error: 'not_found' });
        if (typeof callback === 'function') callback('Room not found');
        return;
      }
      if (socket.sessionId || Object.prototype.hasOwnProperty.call(room.players, playerId)) {
        return typeof callback === 'function' && callback('Already in a room or duplicate player');
      }
      if (String(extra.game_id) !== String(room.gameId)) {
        return typeof callback === 'function' && callback('Game does not match host');
      }
      const slot = Number(extra.player_slot);
      if (!Number.isInteger(slot) || slot < 2 || slot > room.maxPlayers ||
          Object.values(room.players).some(player => player.player_slot === slot)) {
        return typeof callback === 'function' && callback('Player slot unavailable; rejoin from Lobby');
      }
      const roomPassword = (data && data.password) || null;
      if (room.password && room.password !== roomPassword) {
        npLog('join-room_fail', { sessionId, error: 'bad_password' });
        if (typeof callback === 'function') callback('Incorrect password');
        return;
      }
      if (Object.keys(room.players).length >= room.maxPlayers) {
        npLog('join-room_fail', { sessionId, error: 'full', players: Object.keys(room.players).length });
        if (typeof callback === 'function') callback('Room full');
        return;
      }
      room.players[playerId] = { ...extra, socketId: socket.id, player_slot: slot };
      socket.join(sessionId);
      socket.sessionId = sessionId;
      socket.playerId = playerId;
      io.to(sessionId).emit('users-updated', room.players);
      npLog('join-room', {
        sessionId,
        playerId,
        playerName: extra.player_name || null,
        players: Object.keys(room.players).length,
        maxPlayers: room.maxPlayers,
        gameId: room.gameId,
        ageMs: Date.now() - (room.openedAt || Date.now()),
        socketId: socket.id,
      });
      if (typeof callback === 'function') callback(null, room.players);
    } catch (e) {
      npLog('join-room_error', { socketId: socket.id, error: String(e && e.message ? e.message : e) });
      if (typeof callback === 'function') callback('join-room error');
    }
  });

  function leaveRoom() {
    const sessionId = socket.sessionId;
    const playerId = socket.playerId;
    const room = rooms[sessionId];
    delete socket.sessionId;
    delete socket.playerId;
    if (!room) return;
    socket.leave(sessionId);
    if (room.owner === socket.id) {
      // The client has no host migration protocol: never silently promote a guest.
      delete rooms[sessionId];
      io.to(sessionId).emit('room-closed');
      for (const player of Object.values(room.players)) {
        const peer = io.sockets.sockets.get(player.socketId);
        if (peer && peer !== socket) {
          peer.leave(sessionId);
          delete peer.sessionId;
          delete peer.playerId;
        }
      }
      return;
    }
    delete room.players[playerId];
    io.to(sessionId).emit('users-updated', room.players);
  }
  socket.on('leave-room', leaveRoom);
  socket.on('webrtc-signal', (data) => {
    try {
      const { target, candidate, offer, answer, requestRenegotiate } = data || {};
      npLog('webrtc-signal', {
        sessionId: socket.sessionId || null,
        hasTarget: !!target,
        hasOffer: !!offer,
        hasAnswer: !!answer,
        hasCandidate: !!candidate,
        renegotiate: !!requestRenegotiate,
      });
      if (!target && !requestRenegotiate) throw new Error('Target ID missing unless requesting renegotiation');
      if (requestRenegotiate) {
        const targetSocket = io.sockets.sockets.get(target);
        if (targetSocket) {
          targetSocket.emit('webrtc-signal', { sender: socket.id, requestRenegotiate: true });
        }
      } else {
        io.to(target).emit('webrtc-signal', { sender: socket.id, candidate, offer, answer });
      }
    } catch (error) {
      npLog('webrtc-signal_error', { error: error.message, sessionId: socket.sessionId || null });
      console.error(`WebRTC signal error: ${error.message}`);
    }
  });

  socket.on('data-message', (data) => {
    const room = rooms[socket.sessionId];
    const player = room && room.players[socket.playerId];
    if (!player || player.socketId !== socket.id || !data || typeof data !== 'object') return;
    // Frame traffic is ~60 packets/sec: avoid synchronous disk writes per frame.
    if (!data['sync-control'] && data.frame_ack == null) {
      npLog('data-message', { sessionId: socket.sessionId, playerId: socket.playerId, meta: payloadMeta(data) });
    }
    if (room.owner === socket.id) {
      socket.to(socket.sessionId).emit('data-message', data);
    } else if (data.ready === true) {
      io.to(room.owner).emit('data-message', { ready: true, sync_id: data.sync_id, userid: socket.playerId });
    } else if (Number.isInteger(data.frame_ack) && data.frame_ack >= 0) {
      io.to(room.owner).emit('data-message', { frame_ack: data.frame_ack, verified_frame: Number.isInteger(data.verified_frame) && data.verified_frame >= 0 && data.verified_frame <= data.frame_ack ? data.verified_frame : 0, sync_id: data.sync_id, userid: socket.playerId });
    } else if (data.verify_state === true && Number.isInteger(data.frame) && data.frame >= 0) {
      io.to(room.owner).emit('data-message', { verify_state: true, frame: data.frame, sync_id: data.sync_id, userid: socket.playerId });
    } else if (data.sync === true) {
      io.to(room.owner).emit('data-message', { sync: true, sync_id: data.sync_id, reason: data.reason === 'state_mismatch' ? 'state_mismatch' : undefined, frame: Number.isInteger(data.frame) ? data.frame : undefined, userid: socket.playerId });
    } else if (Array.isArray(data['sync-control'])) {
      const inputs = data['sync-control'].slice(0, 64).filter(value =>
        value && Array.isArray(value.connected_input) && value.connected_input.length === 3 &&
        Number.isInteger(value.connected_input[1]) && value.connected_input[1] >= 0 && value.connected_input[1] <= 23 && Number.isFinite(value.connected_input[2])
      ).map(value => ({
        frame: value.frame,
        connected_input: [player.player_slot - 1, value.connected_input[1], value.connected_input[2]],
      }));
      // Only the host applies guest input. Other guests wait for its frame packet.
      io.to(room.owner).emit('data-message', { 'sync-control': inputs, sync_id: data.sync_id, userid: socket.playerId });
    }
  });
  socket.on('snapshot', (data) => {
    const meta = payloadMeta(data);
    npLog('snapshot', { sessionId: socket.sessionId || null, meta });
    if (socket.sessionId) socket.to(socket.sessionId).emit('snapshot', data);
  });

  socket.on('input', (data) => {
    // high-frequency — sample log only
    if (!socket._inLog || Date.now() - socket._inLog > 5000) {
      socket._inLog = Date.now();
      npLog('input_sample', { sessionId: socket.sessionId || null, playerId: socket.playerId || null });
    }
    if (socket.sessionId) socket.to(socket.sessionId).emit('input', data);
  });

  socket.on('disconnect', (reason) => {
    const sessionId = socket.sessionId;
    const playerId = socket.playerId;
    npLog('disconnect', { sessionId: sessionId || null, playerId: playerId || null, socketId: socket.id, reason: String(reason || '') });
    leaveRoom();
  });

  socket.on('error', (err) => {
    npLog('socket_error', { socketId: socket.id, sessionId: socket.sessionId || null, error: String(err && err.message ? err.message : err) });
  });
});

server.listen(PORT, HOST, () => {
  npLog('listen', { host: HOST, port: PORT, logFile: LOG_FILE, version: 'vaultcast-lockstep-4' });
  console.log(`vaultcast-netplay listening on http://${HOST}:${PORT}`);
});
