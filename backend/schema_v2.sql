-- Phase 1 migration: supplier-as-account + payment allocations
-- Idempotent: safe to re-run.

USE procurement;

-- ── suppliers table ───────────────────────────────────
CREATE TABLE IF NOT EXISTS suppliers (
  id         BIGINT PRIMARY KEY AUTO_INCREMENT,
  name       VARCHAR(200) NOT NULL UNIQUE,
  tag        ENUM('strict','flexible','important') NOT NULL DEFAULT 'flexible',
  pinned     TINYINT(1) NOT NULL DEFAULT 0,
  note       VARCHAR(500) DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ── add supplier_id to orders & payments ──────────────
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA='procurement' AND TABLE_NAME='orders' AND COLUMN_NAME='supplier_id');
SET @sql := IF(@col=0, 'ALTER TABLE orders ADD COLUMN supplier_id BIGINT NULL AFTER id, ADD INDEX idx_supplier (supplier_id)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA='procurement' AND TABLE_NAME='payments' AND COLUMN_NAME='supplier_id');
SET @sql := IF(@col=0, 'ALTER TABLE payments ADD COLUMN supplier_id BIGINT NULL AFTER id, ADD INDEX idx_supplier (supplier_id)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA='procurement' AND TABLE_NAME='payments' AND COLUMN_NAME='kind');
SET @sql := IF(@col=0, "ALTER TABLE payments ADD COLUMN kind ENUM('order','account','prepaid') NOT NULL DEFAULT 'order' AFTER amount", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── payment_allocations: links a payment to one or more orders ──
CREATE TABLE IF NOT EXISTS payment_allocations (
  id           BIGINT PRIMARY KEY AUTO_INCREMENT,
  payment_id   BIGINT NOT NULL,
  order_id     BIGINT NOT NULL,
  amount       DECIMAL(14,2) NOT NULL,
  allocated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_auto      TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id)   REFERENCES orders(id)   ON DELETE CASCADE,
  INDEX idx_payment (payment_id),
  INDEX idx_order   (order_id)
) ENGINE=InnoDB;

-- ── DATA MIGRATION (only runs once because of NULL supplier_id check) ──

-- 1) Create supplier rows from distinct names in orders.
INSERT IGNORE INTO suppliers (name)
  SELECT DISTINCT name FROM orders WHERE supplier_id IS NULL;

-- 2) Link orders → supplier
UPDATE orders o
JOIN suppliers s ON s.name = o.name
SET o.supplier_id = s.id
WHERE o.supplier_id IS NULL;

-- 3) Link payments → supplier (via order)
UPDATE payments p
JOIN orders o ON o.id = p.order_id
SET p.supplier_id = o.supplier_id
WHERE p.supplier_id IS NULL;

-- 4) Create allocation row for every existing payment (one-to-one, kind='order')
INSERT INTO payment_allocations (payment_id, order_id, amount, is_auto)
  SELECT p.id, p.order_id, p.amount, 0
  FROM payments p
  LEFT JOIN payment_allocations a ON a.payment_id = p.id
  WHERE a.id IS NULL AND p.order_id IS NOT NULL;

-- 5) Add FK constraint for supplier_id (only if it doesn't exist)
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA='procurement' AND TABLE_NAME='orders'
              AND CONSTRAINT_NAME='fk_orders_supplier');
SET @sql := IF(@fk=0,
  'ALTER TABLE orders ADD CONSTRAINT fk_orders_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA='procurement' AND TABLE_NAME='payments'
              AND CONSTRAINT_NAME='fk_payments_supplier');
SET @sql := IF(@fk=0,
  'ALTER TABLE payments ADD CONSTRAINT fk_payments_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 6) After migration, supplier_id is required on new rows.
--    Make it NOT NULL (only if currently nullable).
SET @nullable := (SELECT IS_NULLABLE FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA='procurement' AND TABLE_NAME='orders' AND COLUMN_NAME='supplier_id');
SET @sql := IF(@nullable='YES', 'ALTER TABLE orders MODIFY supplier_id BIGINT NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @nullable := (SELECT IS_NULLABLE FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA='procurement' AND TABLE_NAME='payments' AND COLUMN_NAME='supplier_id');
SET @sql := IF(@nullable='YES', 'ALTER TABLE payments MODIFY supplier_id BIGINT NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 7) Make payments.order_id nullable (new model: account-level payments aren't tied to one order)
SET @nullable := (SELECT IS_NULLABLE FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA='procurement' AND TABLE_NAME='payments' AND COLUMN_NAME='order_id');
SET @sql := IF(@nullable='NO', 'ALTER TABLE payments MODIFY order_id BIGINT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Sanity check
SELECT
  (SELECT COUNT(*) FROM suppliers)           AS suppliers,
  (SELECT COUNT(*) FROM orders)              AS orders,
  (SELECT COUNT(*) FROM payments)            AS payments,
  (SELECT COUNT(*) FROM payment_allocations) AS allocations;
