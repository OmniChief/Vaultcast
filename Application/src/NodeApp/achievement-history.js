'use strict';

const { q } = require('./queries');
const { documents, enabled, now } = require('./account-data');
const { award, recordWatch, withUser, invalidateRarity } = require('./watch-achievements');

async function watchHistory(connection, userId, time) {
  let after = '';
  const shows = new Set();
  for (;;) {
    const rows = await q(connection,
      'SELECT p.item_id,p.updated_at,p.payload,b.media_kind,b.type,b.show_key,b.season,b.year ' +
      'FROM vaultcast_progress p JOIN vaultcast_browse_items b ON b.item_id=p.item_id ' +
      'WHERE p.user_id=? AND p.item_id>? ORDER BY p.item_id LIMIT 300', [userId, after]);
    for (const item of rows) {
      let progress;
      try { progress = JSON.parse(item.payload); } catch { continue; }
      if (!progress) continue;
      const duration = Number(progress.duration) || 0;
      if (!(Number(progress.viewings) > 0 || (duration > 0 && progress.position / duration > 0.92))) continue;
      if (await recordWatch(connection, userId, item.item_id, item, duration, Number(item.updated_at) || time, false)
          && item.show_key) shows.add(item.show_key);
    }
    if (rows.length < 300) break;
    after = rows[rows.length - 1].item_id;
  }
  for (const show of shows) {
    const [counts] = await q(connection,
      "SELECT COUNT(*) AS episodes,COUNT(m.mark) AS finished FROM vaultcast_browse_items b " +
      "LEFT JOIN vaultcast_achievement_marks m ON m.user_id=? AND m.mark=CONCAT('e:',b.item_id) " +
      "WHERE b.show_key=? AND b.media_kind='video'", [userId, show]);
    if (Number(counts.episodes) >= 2 && Number(counts.finished) === Number(counts.episodes)) {
      await q(connection, 'INSERT IGNORE INTO vaultcast_achievement_marks (user_id,mark,created_at) VALUES (?,?,?)',
        [userId, 's:' + show, time]);
    }
  }
}

function wordHistory(history) {
  let wins = 0, quality = 0, streak = 0, best = 0, previousDay = NaN;
  for (const date of Object.keys(history).sort()) {
    const result = history[date];
    if (!enabled(result?.won)) { streak = 0; continue; }
    const day = Date.parse(date + 'T00:00:00Z') / 86400000;
    streak = day === previousDay + 1 ? streak + 1 : 1;
    previousDay = day;
    best = Math.max(best, streak);
    wins++;
    quality = Math.max(quality, 7 - (Number(result.guesses ?? 7) || 0));
  }
  return { word_wins: wins, word_quality: quality, word_streak: best };
}

async function rebuild(connection, userId) {
  const time = now();
  await watchHistory(connection, userId, time);
  const counts = { games: 0 };
  const scores = await q(connection,
    "SELECT game,COUNT(*) AS n,MAX(score) AS best,SUM(meta='win') AS wins,SUM(meta='daily') AS daily " +
    'FROM vaultcast_play_scores WHERE user_id=? GROUP BY game', [userId]);
  for (const score of scores) {
    counts.games += Number(score.n);
    if (score.game === 'chess') counts.chess_wins = Number(score.wins);
    if (score.game === 'quiz') { counts.quiz_wins = Number(score.wins); counts.quiz_daily = Number(score.daily); }
    if (!['blocks', 'snake', 'merge', 'breakout'].includes(score.game)) continue;
    counts['best_' + score.game] = Number(score.best);
    if (counts['best_' + score.game] > 0) await q(connection,
      'INSERT IGNORE INTO vaultcast_achievement_marks (user_id,mark,created_at) VALUES (?,?,?)',
      [userId, 'rg:' + score.game, time]);
  }
  const tiles = await q(connection,
    "SELECT meta FROM vaultcast_play_scores WHERE user_id=? AND game='merge' AND meta LIKE '%tile:%' ORDER BY score DESC LIMIT 50",
    [userId]);
  for (const row of tiles) {
    for (const match of row.meta.matchAll(/tile:(\d{1,7})/g)) counts.tile = Math.max(counts.tile || 0, Number(match[1]));
  }
  const [points] = await q(connection,
    "SELECT SUM(points) AS total,MAX(source='social') AS team FROM vaultcast_play_points WHERE user_id=?", [userId]);
  const [emulator] = await q(connection,
    'SELECT SUM(sessions) AS sessions FROM vaultcast_play_emulator WHERE user_id=?', [userId]);
  counts.points = Number(points.total);
  counts.emu_sessions = Number(emulator.sessions);

  const docs = await documents(connection, ['play-teams', 'play-word:' + userId, 'home-hub:' + userId, 'misc-boards:' + userId]);
  const team = Object.values(docs['play-teams']?.teams ?? {}).find(t => t?.members?.[userId] != null);
  if (team || Number(points.team)) counts.team = 1;
  if (team) counts.team_size = Object.keys(team.members).length;
  counts.friends = Object.values(docs['home-hub:' + userId]?.friends ?? {}).filter(state => state === 'friend').length;
  const boards = Object.values(docs['misc-boards:' + userId]?.boards ?? {});
  counts.boards = boards.length;
  counts.widgets = boards.reduce((total, board) => total + Object.keys(board?.widgets ?? {}).length, 0);
  Object.assign(counts, wordHistory(docs['play-word:' + userId]?.history ?? {}));

  await q(connection, 'INSERT IGNORE INTO vaultcast_achievement_marks (user_id,mark,created_at) ' +
    "SELECT u.user_id,CONCAT('q:',r.id),? FROM vaultcast_media_request_users u JOIN vaultcast_media_requests r " +
    "ON r.id=u.request_id WHERE u.user_id=? AND r.status='available'", [time, userId]);
  const markCounters = { f: 'films', e: 'episodes', d: 'decades', g: 'genres', s: 'series', c: 'cinema',
    rg: 'run_games', q: 'requests', r: 'reviews' };
  const marks = await q(connection,
    "SELECT SUBSTRING_INDEX(mark,':',1) AS prefix,COUNT(*) AS n FROM vaultcast_achievement_marks WHERE user_id=? GROUP BY prefix",
    [userId]);
  for (const { prefix, n } of marks) if (Object.hasOwn(markCounters, prefix)) counts[markCounters[prefix]] = Number(n);
  counts.backfilled = 1;
  for (const [counter, value] of Object.entries(counts)) {
    await q(connection, 'INSERT INTO vaultcast_achievement_counters (user_id,counter,value,updated_at) VALUES (?,?,?,?) ' +
      'ON DUPLICATE KEY UPDATE value=GREATEST(value,VALUES(value)),updated_at=VALUES(updated_at)', [userId, counter, value, time]);
  }
  return award(connection, userId, time, true);
}

async function backfilled(connection, userId) {
  const [row] = await q(connection,
    "SELECT value FROM vaultcast_achievement_counters WHERE user_id=? AND counter='backfilled'", [userId]);
  return Number(row?.value) >= 1;
}

module.exports = async function ensureHistory(root, pool, userId) {
  if (await backfilled(pool, userId)) return;
  const added = await withUser(pool, userId, async connection => {
    if (await backfilled(connection, userId)) return 0;
    return rebuild(connection, userId);
  });
  if (added) await invalidateRarity(root);
};
