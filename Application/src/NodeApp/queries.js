'use strict';

const q = async (pool, sql, args = []) => (await pool.execute(sql, args))[0];

async function transaction(pool, operation) {
  const connection = await pool.getConnection();
  let usable = true;
  try {
    await connection.beginTransaction();
    const value = await operation(connection);
    await connection.commit();
    return value;
  } catch (error) {
    try {
      await connection.rollback();
    } catch (failure) {
      usable = false;
      connection.destroy();
    }
    throw error;
  } finally {
    if (usable) connection.release();
  }
}

module.exports = { q, transaction };
