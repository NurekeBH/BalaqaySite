// Seed 25 random suppliers (each with 1-4 orders, some payments).
// Run: node seed_random.js   (backend must be running)
//
// Idempotent-ish: if a supplier name already exists, the POST will return 409 and we skip orders.

const API = `http://localhost:${process.env.PORT || 3001}/api`;

// Realistic Chinese supplier names + their primary category + a default tag
const SUPPLIERS = [
  ['华美箱包',   '箱包',     'flexible'],
  ['永盛皮具',   '箱包',     'strict'],
  ['杭州小马',   '箱包',     'flexible'],
  ['老周皮具',   '箱包',     'flexible'],
  ['浙江华远',   '箱包',     'flexible'],
  ['童年时光',   '服装-童装', 'flexible'],
  ['小天才童装', '服装-童装', 'important'],
  ['深圳明威',   '服装-童装', 'flexible'],
  ['广州赵姐',   '服装-童装', 'flexible'],
  ['海洋之星',   '服装-成人', 'flexible'],
  ['都市风尚',   '服装-成人', 'flexible'],
  ['张总（广州）', '服装-成人', 'important'],
  ['雅诺时装',   '服装-成人', 'flexible'],
  ['飞跃运动',   '鞋类',     'strict'],
  ['一鸣鞋业',   '鞋类',     'flexible'],
  ['福建鞋城',   '鞋类',     'flexible'],
  ['林总',       '鞋类',     'strict'],
  ['温州东海',   '鞋类',     'flexible'],
  ['红顶帽业',   '帽子',     'flexible'],
  ['阳光帽厂',   '帽子',     'flexible'],
  ['义乌万家',   '义乌杂货', 'flexible'],
  ['王老板（义乌）', '义乌杂货', 'strict'],
  ['义乌李姐',   '义乌杂货', 'flexible'],
  ['李工',       '其他',     'flexible'],
  ['海宁皮草',   '其他',     'important'],
];

const SHIP_STATUSES = ['ordered', 'shipped', 'transit', 'arrived'];
const ORDER_NOTES = ['', '', '', '电汇', '分期付', '预付30%', '尾款', '微信红包给', '老客户折扣', '整柜货', ''];

function rand(min, max) { return Math.floor(Math.random() * (max - min + 1)) + min; }
function pick(arr) { return arr[Math.floor(Math.random() * arr.length)]; }
function ago(days) {
  const d = new Date();
  d.setDate(d.getDate() - days);
  return d.toISOString().slice(0, 10);
}

async function post(path, body) {
  const r = await fetch(API + path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  if (!r.ok) { throw new Error(`${r.status} ${await r.text()}`); }
  return r.json();
}
async function put(path, body) {
  const r = await fetch(API + path, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  if (!r.ok) { throw new Error(`${r.status} ${await r.text()}`); }
  return r.json();
}

function randomOrder(cat) {
  // Order date 5–365 days ago
  const daysOld = rand(5, 365);
  const total = rand(20, 800) * 1000;  // ¥20K–¥800K, rounded to nearest 1K

  // Ship status — older orders skew toward arrived
  let ship;
  if (daysOld > 90) ship = pick(['arrived', 'arrived', 'arrived', 'transit']);
  else if (daysOld > 30) ship = pick(['arrived', 'transit', 'transit', 'shipped']);
  else ship = pick(['ordered', 'shipped', 'transit', 'transit']);

  let shipDate = '', etaDate = '';
  if (ship !== 'ordered') {
    shipDate = ago(daysOld - rand(2, 10));
    // For transit/shipped: ETA might be in the past (→ delayed) or future
    const etaOffset = ship === 'arrived' ? rand(5, 20) : rand(-10, 30);
    etaDate = ago(daysOld - rand(2, 10) - etaOffset);
  }

  // Initial paid amount: depends on status
  let initialPaid = 0;
  const paidRoll = Math.random();
  if (ship === 'arrived') {
    initialPaid = paidRoll < 0.7 ? total : Math.round(total * (0.3 + Math.random() * 0.5));
  } else if (ship === 'transit' || ship === 'shipped') {
    initialPaid = paidRoll < 0.3 ? 0 : Math.round(total * (0.2 + Math.random() * 0.6));
  } else {
    initialPaid = paidRoll < 0.6 ? 0 : Math.round(total * (0.1 + Math.random() * 0.4));
  }

  return {
    date: ago(daysOld),
    cat,
    total,
    note: pick(ORDER_NOTES),
    ship,
    shipDate,
    etaDate,
    initialPaid,
    initialPayNote: initialPaid === total ? '电汇全款' : initialPaid > 0 ? '预付款' : '',
  };
}

(async () => {
  // Snapshot current supplier names to avoid duplicates
  const existing = await fetch(API + '/suppliers').then(r => r.json()).then(arr => new Set(arr.map(s => s.name)));

  let supCreated = 0, orderCreated = 0, payCreated = 0;

  for (const [name, cat, tag] of SUPPLIERS) {
    if (existing.has(name)) { console.log(`- skip ${name} (exists)`); continue; }

    // Create supplier (will be created with first order, but we want to set tag/category explicitly)
    let supplierId;
    try {
      const r = await post('/suppliers', { name, primaryCategory: cat, tag });
      supplierId = r.id;
      supCreated++;
    } catch (e) {
      console.log(`! ${name}: ${e.message}`);
      continue;
    }

    // 1–4 random orders
    const n = rand(1, 4);
    for (let i = 0; i < n; i++) {
      const o = randomOrder(cat);
      try {
        await post('/orders', { supplierId, ...o });
        orderCreated++;
      } catch (e) {
        console.log(`! order for ${name}: ${e.message}`);
      }
    }

    // 30% chance: an additional "account" payment (FIFO) on top of initial paids
    if (Math.random() < 0.3) {
      const amount = rand(10, 200) * 1000;
      try {
        await post('/payments', {
          supplierId,
          date: ago(rand(0, 30)),
          amount,
          kind: 'account',
          note: pick(['补打', '尾款', '月末结清', '回款']),
        });
        payCreated++;
      } catch (e) {}
    }

    // 15% chance: pin the supplier
    if (Math.random() < 0.15) {
      try { await put('/suppliers/' + supplierId, { pinned: true }); } catch (e) {}
    }

    console.log(`✓ ${tag.padEnd(9)} [${cat}] ${name} (${n} orders)`);
  }

  console.log(`\nDone. Created: ${supCreated} suppliers, ${orderCreated} orders, ${payCreated} account-payments.`);
})();
