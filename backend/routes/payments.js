const express = require('express');
const db = require('../db');

const router = express.Router();

// FIFO-allocate `amount` across the supplier's outstanding orders (oldest first).
// Stops when amount is exhausted or no more unpaid orders. Returns the rows inserted.
async function allocateFIFO(conn, paymentId, supplierId, amount) {
  // Find orders with debt = total - already_allocated, oldest first
  const [orders] = await conn.query(
    `SELECT o.id, o.total,
        COALESCE((SELECT SUM(amount) FROM payment_allocations WHERE order_id = o.id), 0) AS allocated
       FROM orders o
       WHERE o.supplier_id = ?
       ORDER BY o.order_date ASC, o.id ASC`,
    [supplierId]
  );

  let remaining = Number(amount);
  const inserted = [];
  for (const o of orders) {
    if (remaining <= 0.0001) break;
    const debt = Number(o.total) - Number(o.allocated);
    if (debt <= 0.0001) continue;
    const take = Math.min(debt, remaining);
    await conn.query(
      'INSERT INTO payment_allocations (payment_id, order_id, amount, is_auto) VALUES (?, ?, ?, 1)',
      [paymentId, o.id, take]
    );
    inserted.push({ orderId: o.id, amount: take });
    remaining -= take;
  }
  return { allocated: Number(amount) - remaining, leftover: remaining, rows: inserted };
}

// POST /api/payments
// Body: { supplierId, date, amount, kind, note?, photo?, orderId? }
// kind = 'order' (orderId required), 'account' (FIFO across supplier's debt), 'prepaid' (held, no allocation)
router.post('/', async (req, res, next) => {
  const conn = await db.getConnection();
  try {
    await conn.beginTransaction();
    let { supplierId, date, amount, kind = 'order', note = '', photo = '', orderId = null, rate = null } = req.body;
    amount = Number(amount);
    if (!supplierId) throw new Error('supplierId required');
    if (!date) throw new Error('date required');
    if (!(amount > 0)) throw new Error('amount must be > 0');
    rate = rate != null ? Number(rate) : null;

    // If kind=order, sanity check the order belongs to this supplier
    if (kind === 'order') {
      if (!orderId) throw new Error('orderId required for kind=order');
      const [[o]] = await conn.query('SELECT supplier_id FROM orders WHERE id=?', [orderId]);
      if (!o) throw new Error('order not found');
      if (o.supplier_id !== Number(supplierId)) throw new Error('order does not belong to supplier');
    } else {
      orderId = null;
    }

    const [r] = await conn.query(
      'INSERT INTO payments (supplier_id, order_id, pay_date, amount, rate, kind, note, photo_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
      [supplierId, orderId, date, amount, rate, kind, note, photo]
    );
    const paymentId = r.insertId;

    let allocResult = null;
    if (kind === 'order') {
      await conn.query(
        'INSERT INTO payment_allocations (payment_id, order_id, amount, is_auto) VALUES (?, ?, ?, 0)',
        [paymentId, orderId, amount]
      );
    } else if (kind === 'account') {
      allocResult = await allocateFIFO(conn, paymentId, supplierId, amount);
    }
    // kind=prepaid: no allocation, money sits on the supplier's account

    await conn.commit();
    res.json({ id: paymentId, allocation: allocResult });
  } catch (e) {
    await conn.rollback();
    next(e);
  } finally {
    conn.release();
  }
});

// PUT /api/payments/:id — edit metadata only (date / note / photo). To change amount or re-allocate, delete & recreate.
router.put('/:id', async (req, res, next) => {
  try {
    const { date, note, photo, rate } = req.body;
    const fields = [], vals = [];
    if (date  !== undefined) { fields.push('pay_date=?');   vals.push(date); }
    if (note  !== undefined) { fields.push('note=?');       vals.push(note); }
    if (photo !== undefined) { fields.push('photo_path=?'); vals.push(photo); }
    if (rate  !== undefined) { fields.push('rate=?');       vals.push(rate); }
    if (!fields.length) return res.json({ ok: true });
    vals.push(req.params.id);
    await db.query(`UPDATE payments SET ${fields.join(', ')} WHERE id=?`, vals);
    res.json({ ok: true });
  } catch (e) { next(e); }
});

// DELETE /api/payments/:id — cascades to allocations
router.delete('/:id', async (req, res, next) => {
  try {
    await db.query('DELETE FROM payments WHERE id=?', [req.params.id]);
    res.json({ ok: true });
  } catch (e) { next(e); }
});

// POST /api/payments/:id/reallocate — Phase 2 stub for manual re-allocation
router.post('/:id/reallocate', async (req, res, next) => {
  const conn = await db.getConnection();
  try {
    await conn.beginTransaction();
    const paymentId = req.params.id;
    const { allocations } = req.body; // [{ orderId, amount }, ...]
    if (!Array.isArray(allocations)) throw new Error('allocations array required');
    const [[p]] = await conn.query('SELECT amount, supplier_id FROM payments WHERE id=?', [paymentId]);
    if (!p) throw new Error('payment not found');
    const sum = allocations.reduce((a, x) => a + Number(x.amount), 0);
    if (sum > Number(p.amount) + 0.001) throw new Error('allocation sum exceeds payment amount');

    await conn.query('DELETE FROM payment_allocations WHERE payment_id=?', [paymentId]);
    for (const a of allocations) {
      await conn.query(
        'INSERT INTO payment_allocations (payment_id, order_id, amount, is_auto) VALUES (?, ?, ?, 0)',
        [paymentId, a.orderId, a.amount]
      );
    }
    await conn.commit();
    res.json({ ok: true });
  } catch (e) {
    await conn.rollback();
    next(e);
  } finally {
    conn.release();
  }
});

module.exports = router;
