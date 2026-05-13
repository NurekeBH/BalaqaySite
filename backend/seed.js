// Original test data from the localStorage seedData() — load into the DB via REST API.
// Run: node seed.js   (backend must be running on PORT)

const API = `http://localhost:${process.env.PORT || 3001}/api`;

function ago(days) {
  const d = new Date();
  d.setDate(d.getDate() - days);
  return d.toISOString().slice(0, 10);
}

const ORDERS = [
  {
    date: ago(60), name: '天才少年', cat: '箱包', total: 81450, note: '',
    ship: 'arrived', shipDate: ago(55), etaDate: ago(40),
    payments: [{ date: ago(60), amount: 81450, note: '电汇全款', photo: '' }],
    photos: [],
  },
  {
    date: ago(45), name: '新梦想', cat: '箱包', total: 289500, note: '',
    ship: 'arrived', shipDate: ago(40), etaDate: ago(25),
    payments: [
      { date: ago(45), amount: 100000, note: '预付款', photo: '' },
      { date: ago(20), amount: 189500, note: '尾款', photo: '' },
    ],
    photos: [],
  },
  {
    date: ago(30), name: 'Zakas Baibol', cat: '鞋类', total: 1003800,
    note: '整车货 · 应该已到却没到',
    ship: 'transit', shipDate: ago(20), etaDate: ago(5),
    payments: [{ date: ago(30), amount: 455654, note: '首期', photo: '' }],
    photos: [],
  },
  {
    date: ago(20), name: 'Eric Jeans', cat: '服装-童装', total: 418700, note: '',
    ship: 'transit', shipDate: ago(15), etaDate: ago(-3),
    payments: [{ date: ago(20), amount: 418700, note: '电汇全款', photo: '' }],
    photos: [],
  },
  {
    date: ago(10), name: '广州 Chen', cat: '服装-成人', total: 589540, note: '分期付',
    ship: 'shipped', shipDate: ago(5), etaDate: ago(-10),
    payments: [
      { date: ago(10), amount: 150000, note: '预付款', photo: '' },
      { date: ago(3),  amount: 150000, note: '第二期', photo: '' },
    ],
    photos: [],
  },
  {
    date: ago(3), name: '李总（义乌）', cat: '义乌杂货', total: 150000, note: '赊账',
    ship: 'ordered', shipDate: '', etaDate: '',
    payments: [],
    photos: [],
  },
];

(async () => {
  for (const o of ORDERS) {
    const r = await fetch(`${API}/orders`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(o),
    });
    if (!r.ok) { console.error('Failed:', o.name, await r.text()); continue; }
    const { id } = await r.json();
    console.log(`✓ #${id}  ${o.name}  ¥${o.total.toLocaleString()}`);
  }
  console.log('Done.');
})();
