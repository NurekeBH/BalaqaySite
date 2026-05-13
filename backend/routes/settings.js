const express = require('express');
const db = require('../db');

const router = express.Router();

router.get('/', async (_req, res, next) => {
  try {
    const [rows] = await db.query('SELECT k, v FROM settings');
    const obj = {};
    rows.forEach(r => { obj[r.k] = r.v; });
    res.json(obj);
  } catch (e) { next(e); }
});

router.put('/:key', async (req, res, next) => {
  try {
    const { value } = req.body;
    await db.query(
      'INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v=VALUES(v)',
      [req.params.key, String(value)]
    );
    res.json({ ok: true });
  } catch (e) { next(e); }
});

module.exports = router;
