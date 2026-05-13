const express = require('express');
const multer = require('multer');
const path = require('path');
const fs = require('fs');
const db = require('../db');

const router = express.Router();

const uploadDir = path.join(__dirname, '..', 'uploads');
if (!fs.existsSync(uploadDir)) fs.mkdirSync(uploadDir, { recursive: true });

const storage = multer.diskStorage({
  destination: (_req, _file, cb) => cb(null, uploadDir),
  filename: (_req, file, cb) => {
    const ext = path.extname(file.originalname) || '.jpg';
    cb(null, `${Date.now()}-${Math.random().toString(36).slice(2, 8)}${ext}`);
  },
});
const upload = multer({ storage, limits: { fileSize: 10 * 1024 * 1024 } });

// POST /api/photos/upload — multipart form, field name "photo"
// Returns { url: '/uploads/xxx.jpg' }
router.post('/upload', upload.single('photo'), (req, res) => {
  if (!req.file) return res.status(400).json({ error: 'no file' });
  res.json({ url: `/uploads/${req.file.filename}` });
});

// POST /api/photos/order/:orderId — attach an already-uploaded photo url to an order
router.post('/order/:orderId', async (req, res, next) => {
  try {
    const { url } = req.body;
    if (!url) return res.status(400).json({ error: 'url required' });
    const [r] = await db.query(
      'INSERT INTO order_photos (order_id, photo_path) VALUES (?, ?)',
      [req.params.orderId, url]
    );
    res.json({ id: r.insertId });
  } catch (e) { next(e); }
});

// DELETE /api/photos/order/:orderId — remove by photo_path
router.delete('/order/:orderId', async (req, res, next) => {
  try {
    const { url } = req.body;
    await db.query(
      'DELETE FROM order_photos WHERE order_id=? AND photo_path=?',
      [req.params.orderId, url]
    );
    res.json({ ok: true });
  } catch (e) { next(e); }
});

module.exports = router;
