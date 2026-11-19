'use strict';

const { createHash } = require('crypto');
const { unlink } = require('fs').promises;
const { join } = require('path');
const definitions = require('./achievement-definitions.json');
const { sha } = require('./account-data');
const { q, transaction } = require('./queries');

const excluded = new Set(['music', 'roms', 'games', 'audiobooks', 'podcasts', 'photos']);
const tiers = { bronze: 'Bronze', silver: 'Silver', gold: 'Gold', platinum: 'Platinum' };

async function notify(connection, userId, subtype, dedupe, title, body, payload, time) {
  await q(connection,
    "INSERT INTO vaultcast_inbox (user_id,type,subtype,dedupe,actor,title,body,action,payload,created_at) " +
    "VALUES (?,'achievement',?,?,'',?,?,?,?,?) ON DUPLICATE KEY UPDATE " +
    'deleted=IF(VALUES(created_at)>created_at,0,deleted),read_at=IF(VALUES(created_at)>created_at,0,read_at),' +
    'type=VALUES(type),subtype=VALUES(subtype),actor=VALUES(actor),title=VALUES(title),body=VALUES(body),' +
    'action=VALUES(action),payload=VALUES(payload),created_at=GREATEST(created_at,VALUES(created_at))',
    [userId, subtype, dedupe, title, body, JSON.stringify({ kind: 'path', path: 'play/achievements' }),
      JSON.stringify(payload), time]);
}

async function award(connection, userId, time, quiet = false) {
  const [counters] = await connection.execute('SELECT counter,value FROM vaultcast_achievement_counters WHERE user_id=?', [userId]);
  const [awards] = await connection.execute('SELECT ach_id FROM vaultcast_achievements WHERE user_id=?', [userId]);
  const values = new Map(counters.map(row => [row.counter, Number(row.value)]));
  const earned = new Set(awards.map(row => row.ach_id));
  let added = 0;
  for (let pass = 0; pass < 2; pass++) {
    values.set('earned', earned.size);
    for (const [id, [, tier, icon, title, description, counter, goal]] of Object.entries(definitions)) {
      if (earned.has(id) || (values.get(counter) ?? 0) < goal) continue;
      const [result] = await connection.execute(
        'INSERT IGNORE INTO vaultcast_achievements (user_id,ach_id,earned_at,toasted,showcase) VALUES (?,?,?,?,0)',
        [userId, id, time, Number(quiet)]);
      earned.add(id);
      if (!result.affectedRows) continue;
      if (!quiet) await notify(connection, userId, tier, 'ach:' + id, 'Achievement unlocked: ' + title,
        description + ' · ' + tiers[tier], { achievement: id, icon, tier }, time);
      added++;
    }
    if (!added) break;
  }
  if (quiet && added) await notify(connection, userId, 'history', 'ach-history',
    added === 1 ? 'You already earned an achievement' : 'You already earned ' + added + ' achievements',
    'Your history on this server counts. Have a look at what you have unlocked so far.', { count: added }, time);
  return added;
}

async function recordWatch(connection, userId, itemId, item, duration, time, live) {
  const mark = async name => {
    const [result] = await connection.execute(
      'INSERT IGNORE INTO vaultcast_achievement_marks (user_id,mark,created_at) VALUES (?,?,?)', [userId, name, time]);
    return result.affectedRows > 0;
  };
  const counter = (name, value, increment = true) => connection.execute(
    'INSERT INTO vaultcast_achievement_counters (user_id,counter,value,updated_at) VALUES (?,?,?,?) ' +
    'ON DUPLICATE KEY UPDATE value=' + (increment ? 'value+VALUES(value)' : 'GREATEST(value,VALUES(value))') +
    ',updated_at=VALUES(updated_at)', [userId, name, value, time]);

  if (item?.media_kind !== 'video' || excluded.has(item.type)) return false;
  const episode = item.show_key !== '';
  if (!await mark((episode ? 'e:' : 'f:') + itemId)) return false;
  await counter(episode ? 'episodes' : 'films', 1);
  const seconds = Math.round(Math.max(0, Math.min(14400, duration)));
  if (seconds) await counter('watch_seconds', seconds);
  const date = new Date(time * 1000);
  const today = new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime() / 1000;

  if (!episode) {
    const year = Number(item.year);
    if (year >= 1880 && year <= 2100 && await mark('d:' + Math.floor(year / 10) * 10)) await counter('decades', 1);
    const [facts] = await connection.execute('SELECT genres FROM vaultcast_smart_facts WHERE item_id=?', [itemId]);
    for (const genre of String(facts[0]?.genres ?? '').split('|').filter(Boolean).slice(0, 12)) {
      const hash = createHash('sha1').update(genre.toLowerCase()).digest('hex').slice(0, 20);
      if (await mark('g:' + hash)) await counter('genres', 1);
    }
    if (!live) return true;
    if (date.getHours() < 5) await counter('night', 1, false);
    const [[{ count }]] = await connection.execute(
      "SELECT COUNT(*) AS count FROM vaultcast_achievement_marks WHERE user_id=? AND mark LIKE 'f:%' AND created_at>=?",
      [userId, today]);
    if (Number(count) >= 3) await counter('triple', 1, false);
  } else if (live) {
    const day = date.getDay();
    const weekend = today - (day === 0 ? 86400 : 0);
    const [[series]] = await connection.execute(
      "SELECT COUNT(*) AS episodes, COUNT(m.mark) AS finished, SUM(b.season=?) AS season_episodes, " +
      'SUM(b.season=? AND m.created_at>=?) AS weekend_finished FROM vaultcast_browse_items b ' +
      "LEFT JOIN vaultcast_achievement_marks m ON m.user_id=? AND m.mark=CONCAT('e:',b.item_id) " +
      "WHERE b.show_key=? AND b.media_kind='video'",
      [item.season, item.season, weekend, userId, item.show_key]);
    if (Number(series.episodes) >= 2 && Number(series.episodes) === Number(series.finished) && await mark('s:' + item.show_key)) {
      await counter('series', 1);
    }
    if ((day === 6 || day === 0) && Number(series.season_episodes) >= 3 &&
        Number(series.season_episodes) === Number(series.weekend_finished)) await counter('binge', 1, false);
  }
  return true;
}

async function withUser(pool, userId, operation) {
  return transaction(pool, async connection => {
    const users = await q(connection, 'SELECT id FROM vaultcast_users WHERE id=? FOR UPDATE', [userId]);
    if (!users.length) throw new Error('Achievement owner no longer exists.');
    return operation(connection);
  });
}

async function invalidateRarity(root) {
  const key = sha('achievements-rarity');
  await unlink(join(root, 'data/cache/kv', key.slice(0, 2), key + '.json')).catch(() => {});
}

async function watchAchievements(root, pool, user, itemId, duration, time) {
  const userId = user.id;
  if (!userId || userId === 'public' || !user.role || user.role === 'guest') return 0;
  const [existing] = await pool.execute(
    'SELECT 1 FROM vaultcast_achievement_marks WHERE user_id=? AND mark=?', [userId, 'w:' + itemId]);
  if (existing.length) return 0;

  const added = await withUser(pool, userId, async connection => {
    const [mark] = await connection.execute(
      'INSERT IGNORE INTO vaultcast_achievement_marks (user_id,mark,created_at) VALUES (?,?,?)', [userId, 'w:' + itemId, time]);
    if (!mark.affectedRows) return 0;
    const [item] = await q(connection,
      'SELECT media_kind,type,show_key,season,year FROM vaultcast_browse_items WHERE item_id=?', [itemId]);
    if (!await recordWatch(connection, userId, itemId, item, duration, time, true)) return 0;
    return award(connection, userId, time);
  });
  if (added) await invalidateRarity(root);
  return added;
}

module.exports = { watchAchievements, recordWatch, award, withUser, invalidateRarity, tiers };
