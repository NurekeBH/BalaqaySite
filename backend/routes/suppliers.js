const express = require('express');
const db = require('../db');

const router = express.Router();

// Aggregated supplier list: total_ordered / total_paid / allocated / owed / account_balance
const AGG_SQL = `
  SELECT
    s.id, s.name, s.primary_category, s.tag, s.pinned, s.note, s.created_at,
    COALESCE(o.order_count, 0)   AS order_count,
    COALESCE(o.total_ordered, 0) AS total_ordered,
    COALESCE(o.total_ordered_tg, 0) AS total_ordered_tg,
    COALESCE(p.total_paid, 0)    AS total_paid,
    COALESCE(p.total_paid_tg, 0) AS total_paid_tg,
    COALESCE(a.total_allocated, 0) AS total_allocated,
    COALESCE(o.total_ordered, 0)  - COALESCE(a.total_allocated, 0) AS owed,
    COALESCE(p.total_paid, 0)     - COALESCE(a.total_allocated, 0) AS account_balance,
    (SELECT MAX(order_date) FROM orders WHERE supplier_id = s.id) AS last_order_date
  FROM suppliers s
  LEFT JOIN (
    SELECT supplier_id, COUNT(*) AS order_count, SUM(total) AS total_ordered,
           SUM(total * COALESCE(rate, 65.5)) AS total_ordered_tg
    FROM orders GROUP BY supplier_id
  ) o ON o.supplier_id = s.id
  LEFT JOIN (
    SELECT supplier_id, SUM(amount) AS total_paid,
           SUM(amount * COALESCE(rate, 65.5)) AS total_paid_tg
    FROM payments GROUP BY supplier_id
  ) p ON p.supplier_id = s.id
  LEFT JOIN (
    SELECT p.supplier_id, SUM(a.amount) AS total_allocated
    FROM payment_allocations a JOIN payments p ON p.id = a.payment_id
    GROUP BY p.supplier_id
  ) a ON a.supplier_id = s.id
`;

function shape(r) {
  return {
    id: r.id,
    name: r.name,
    primaryCategory: r.primary_category,
    tag: r.tag,
    pinned: !!r.pinned,
    note: r.note || '',
    orderCount: r.order_count,
    totalOrdered: Number(r.total_ordered),
    totalOrderedTg: Number(r.total_ordered_tg),
    totalPaid: Number(r.total_paid),
    totalPaidTg: Number(r.total_paid_tg),
    totalAllocated: Number(r.total_allocated),
    owed: Number(r.owed),
    owedTg: Number(r.total_ordered_tg) - Number(r.total_paid_tg),
    accountBalance: Number(r.account_balance),
    lastOrderDate: r.last_order_date || null,
  };
}

router.get('/', async (_req, res, next) => {
  try {
    const [rows] = await db.query(AGG_SQL + ' ORDER BY s.pinned DESC, s.name ASC');
    res.json(rows.map(shape));
  } catch (e) { next(e); }
});

router.get('/:id', async (req, res, next) => {
  try {
    const [rows] = await db.query(AGG_SQL + ' WHERE s.id = ?', [req.params.id]);
    if (!rows.length) return res.status(404).json({ error: 'not found' });
    res.json(shape(rows[0]));
  } catch (e) { next(e); }
});

router.post('/', async (req, res, next) => {
  try {
    const { name, primaryCategory, tag, note } = req.body;
    if (!name) return res.status(400).json({ error: 'name required' });
    const [r] = await db.query(
      'INSERT INTO suppliers (name, primary_category, tag, note) VALUES (?, ?, ?, ?)',
      [name, primaryCategory || '其他', tag || 'flexible', note || '']
    );
    res.json({ id: r.insertId });
  } catch (e) {
    if (e.code === 'ER_DUP_ENTRY') return res.status(409).json({ error: 'supplier name already exists' });
    next(e);
  }
});

router.put('/:id', async (req, res, next) => {
  try {
    const { name, primaryCategory, tag, pinned, note } = req.body;
    const fields = [], vals = [];
    if (name !== undefined)            { fields.push('name=?');             vals.push(name); }
    if (primaryCategory !== undefined) { fields.push('primary_category=?'); vals.push(primaryCategory); }
    if (tag !== undefined)             { fields.push('tag=?');              vals.push(tag); }
    if (pinned !== undefined)          { fields.push('pinned=?');           vals.push(pinned ? 1 : 0); }
    if (note !== undefined)            { fields.push('note=?');             vals.push(note); }
    if (!fields.length) return res.json({ ok: true });
    vals.push(req.params.id);
    await db.query(`UPDATE suppliers SET ${fields.join(', ')} WHERE id=?`, vals);
    res.json({ ok: true });
  } catch (e) { next(e); }
});

router.delete('/:id', async (req, res, next) => {
  try {
    // Refuse if any orders exist
    const [[{ n }]] = await db.query('SELECT COUNT(*) AS n FROM orders WHERE supplier_id=?', [req.params.id]);
    if (n > 0) return res.status(409).json({ error: 'supplier has orders' });
    await db.query('DELETE FROM suppliers WHERE id=?', [req.params.id]);
    res.json({ ok: true });
  } catch (e) { next(e); }
});

// GET /api/suppliers/:id/payments — all payments for this supplier with their allocations
router.get('/:id/payments', async (req, res, next) => {
  try {
    const [payments] = await db.query(
      'SELECT * FROM payments WHERE supplier_id=? ORDER BY pay_date DESC, id DESC',
      [req.params.id]
    );
    if (!payments.length) return res.json([]);

    const ids = payments.map(p => p.id);
    const [allocs] = await db.query(
      `SELECT a.*, o.name AS order_name FROM payment_allocations a
       LEFT JOIN orders o ON o.id = a.order_id
       WHERE a.payment_id IN (?)`,
      [ids]
    );
    const allocByPayment = {};
    allocs.forEach(a => {
      (allocByPayment[a.payment_id] = allocByPayment[a.payment_id] || []).push({
        id: a.id,
        orderId: a.order_id,
        amount: Number(a.amount),
        isAuto: !!a.is_auto,
      });
    });

    res.json(payments.map(p => ({
      id: p.id,
      date: p.pay_date,
      amount: Number(p.amount),
      rate: p.rate != null ? Number(p.rate) : null,
      kind: p.kind,
      note: p.note || '',
      photo: p.photo_path || '',
      allocations: allocByPayment[p.id] || [],
    })));
  } catch (e) { next(e); }
});

module.exports = router;
