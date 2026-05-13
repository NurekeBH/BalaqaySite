const express = require('express');
const cors = require('cors');
const path = require('path');
require('dotenv').config();

const ordersRouter = require('./routes/orders');
const paymentsRouter = require('./routes/payments');
const photosRouter = require('./routes/photos');
const settingsRouter = require('./routes/settings');
const suppliersRouter = require('./routes/suppliers');

const app = express();

app.use(cors());
app.use(express.json({ limit: '20mb' }));
app.use('/uploads', express.static(path.join(__dirname, 'uploads')));
app.use(express.static(path.join(__dirname, '..', 'frontend')));

app.use('/api/orders', ordersRouter);
app.use('/api/payments', paymentsRouter);
app.use('/api/photos', photosRouter);
app.use('/api/settings', settingsRouter);
app.use('/api/suppliers', suppliersRouter);

app.get('/api/health', (_req, res) => res.json({ ok: true }));

app.use((err, _req, res, _next) => {
  console.error(err);
  res.status(err.status || 500).json({ error: err.message || 'Server error' });
});

const port = parseInt(process.env.PORT || '3001', 10);
app.listen(port, () => {
  console.log(`Server running on http://localhost:${port}`);
});
