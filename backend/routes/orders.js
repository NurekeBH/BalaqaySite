const express = require('express');
const db = require('../db');

const router = express.Router();

// Resolve supplier — either supplierId is given, or supplierName (create if not exists).
async function resolveSupplier(conn, body) {
  if (body.supplierId) return Number(body.supplierId);
  const name = (body.supplierName || body.name || '').trim();
  if (!name) throw new Error('supplierId or supplierName required');
  const [[existing]] = await conn.query('SELECT id FROM suppliers WHERE name=?', [name]);
  if (existing) return existing.id;
  // New supplier — seed primary_category from this order's cat so the supplier lands in a sensible bucket
  const [r] = await conn.query(
    'INSERT INTO suppliers (name, primary_category) VALUES (?, ?)',
    [name, body.cat || '其他']
  );
  return r.insertId;
}

// Shape: order + its allocations (each allocation = a chunk of some payment applied here)
function shapeOrder(o, allocs, photos) {
  const paid = allocs.reduce((a, x) => a + Number(x.amount), 0);
  return {
    id: o.id,
    supplierId: o.supplier_id,
    supplierName: o.supplier_name,
    supplierTag: o.supplier_tag,
    date: o.order_date,
    name: o.supplier_name,       // back-compat for old UI
    cat: o.category,
    total: Number(o.total),
    rate: o.rate != null ? Number(o.rate) : null,
    note: o.note || '',
    ship: o.ship_status,
    shipDate: o.ship_date || '',
    etaDate: o.eta_date || '',
    paid,
    debt: Math.max(0, Number(o.total) - paid),
    allocations: allocs.map(a => ({
      id: a.id,
      paymentId: a.payment_id,
      paymentDate: a.pay_date,
      paymentNote: a.pay_note,
      paymentKind: a.kind,
      paymentRate: a.pay_rate != null ? Number(a.pay_rate) : null,
      amount: Number(a.amount),
      isAuto: !!a.is_auto,
    })),
    photos: photos.map(p => p.photo_path),
  };
}

// GET /api/orders — full list with computed paid (from allocations) + photos
router.get('/', async (req, res, next) => {
  try {
    const { supplierId } = req.query;
    const where = supplierId ? 'WHERE o.supplier_id = ?' : '';
    const params = supplierId ? [supplierId] : [];
    const [orders] = await db.query(
      `SELECT o.*, s.name AS supplier_name, s.tag AS supplier_tag
         FROM orders o JOIN suppliers s ON s.id = o.supplier_id
         ${where}
         ORDER BY o.order_date DESC, o.id DESC`,
      params
    );
    if (!orders.length) return res.json([]);
    const ids = orders.map(o => o.id);

    const [allocs] = await db.query(
      `SELECT a.*, p.pay_date, p.note AS pay_note, p.kind, p.rate AS pay_rate
         FROM payment_allocations a
         JOIN payments p ON p.id = a.payment_id
         WHERE a.order_id IN (?)
         ORDER BY p.pay_date ASC, a.id ASC`,
      [ids]
    );
    const [photos] = await db.query(
      'SELECT * FROM order_photos WHERE order_id IN (?) ORDER BY id ASC',
      [ids]
    );

    const allocByOrder = {}, photosByOrder = {};
    allocs.forEach(a => (allocByOrder[a.order_id] = allocByOrder[a.order_id] || []).push(a));
    photos.forEach(p => (photosByOrder[p.order_id] = photosByOrder[p.order_id] || []).push(p));

    res.json(orders.map(o => shapeOrder(o, allocByOrder[o.id] || [], photosByOrder[o.id] || [])));
  } catch (e) { next(e); }
});

// POST /api/orders
router.post('/', async (req, res, next) => {
  const conn = await db.getConnection();
  try {
    await conn.beginTransaction();
    const { date, cat, total, note, ship, shipDate, etaDate, photos = [], initialPaid = 0, initialPayNote = '', rate = null } = req.body;
    if (!total) throw new Error('total required');

    const supplierId = await resolveSupplier(conn, req.body);

    const [r] = await conn.query(
      `INSERT INTO orders (supplier_id, order_date, name, category, total, rate, note, ship_status, ship_date, eta_date)
       VALUES (?, ?, (SELECT name FROM suppliers WHERE id=?), ?, ?, ?, ?, ?, ?, ?)`,
      [supplierId, date || new Date().toISOString().slice(0,10), supplierId, cat || '其他',
       total, rate, note || '', ship || 'ordered', shipDate || null, etaDate || null]
    );
    const orderId = r.insertId;

    // Optional first payment
    if (Number(initialPaid) > 0) {
      const [pay] = await conn.query(
        'INSERT INTO payments (supplier_id, order_id, pay_date, amount, rate, kind, note) VALUES (?, ?, ?, ?, ?, "order", ?)',
        [supplierId, orderId, date || new Date().toISOString().slice(0,10), initialPaid, rate, initialPayNote || '首次付款']
      );
      await conn.query(
        'INSERT INTO payment_allocations (payment_id, order_id, amount, is_auto) VALUES (?, ?, ?, 0)',
        [pay.insertId, orderId, initialPaid]
      );
    }

    // Auto-consume prepaid balance via FIFO (so new order against existing balance gets allocated)
    // Reuses the same logic — find unallocated payments (kind=prepaid or account with leftover)
    const [unallocPayments] = await conn.query(
      `SELECT p.id, p.amount - COALESCE((SELECT SUM(amount) FROM payment_allocations WHERE payment_id=p.id),0) AS leftover
         FROM payments p
         WHERE p.supplier_id=? AND p.kind IN ('account','prepaid')
         HAVING leftover > 0.001
         ORDER BY p.pay_date ASC, p.id ASC`,
      [supplierId]
    );
    let needed = Number(total);
    for (const p of unallocPayments) {
      if (needed <= 0.001) break;
      const take = Math.min(Number(p.leftover), needed);
      await conn.query(
        'INSERT INTO payment_allocations (payment_id, order_id, amount, is_auto) VALUES (?, ?, ?, 1)',
        [p.id, orderId, take]
      );
      needed -= take;
    }

    // Photos
    for (const photo of photos) {
      await conn.query('INSERT INTO order_photos (order_id, photo_path) VALUES (?, ?)', [orderId, photo]);
    }
    await conn.commit();
    res.json({ id: orderId, supplierId });
  } catch (e) {
    await conn.rollback();
    next(e);
  } finally {
    conn.release();
  }
});

// PUT /api/orders/:id
router.put('/:id', async (req, res, next) => {
  try {
    const { date, cat, total, rate, note, ship, shipDate, etaDate } = req.body;
    const fields = [], vals = [];
    if (date !== undefined)     { fields.push('order_date=?');  vals.push(date); }
    if (cat !== undefined)      { fields.push('category=?');    vals.push(cat); }
    if (total !== undefined)    { fields.push('total=?');       vals.push(total); }
    if (rate !== undefined)     { fields.push('rate=?');        vals.push(rate); }
    if (note !== undefined)     { fields.push('note=?');        vals.push(note); }
    if (ship !== undefined)     { fields.push('ship_status=?'); vals.push(ship); }
    if (shipDate !== undefined) { fields.push('ship_date=?');   vals.push(shipDate || null); }
    if (etaDate !== undefined)  { fields.push('eta_date=?');    vals.push(etaDate || null); }
    if (!fields.length) return res.json({ ok: true });
    vals.push(req.params.id);
    await db.query(`UPDATE orders SET ${fields.join(', ')} WHERE id=?`, vals);
    res.json({ ok: true });
  } catch (e) { next(e); }
});

router.delete('/:id', async (req, res, next) => {
  try {
    await db.query('DELETE FROM orders WHERE id=?', [req.params.id]);
    res.json({ ok: true });
  } catch (e) { next(e); }
});

module.exports = router;
