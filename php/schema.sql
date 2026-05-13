-- baura.kz / ps.kz — full schema, run once in Plesk → Базы данных → phpMyAdmin
-- Does NOT include CREATE DATABASE — Plesk creates the DB for you; just import this into that DB.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS suppliers (
  id               BIGINT PRIMARY KEY AUTO_INCREMENT,
  name             VARCHAR(200) NOT NULL UNIQUE,
  primary_category VARCHAR(50)  NOT NULL DEFAULT '其他',
  tag              ENUM('strict','flexible','important') NOT NULL DEFAULT 'flexible',
  pinned           TINYINT(1)   NOT NULL DEFAULT 0,
  note             VARCHAR(500) DEFAULT '',
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders (
  id           BIGINT PRIMARY KEY AUTO_INCREMENT,
  supplier_id  BIGINT NOT NULL,
  order_date   DATE         NOT NULL,
  name         VARCHAR(200) NOT NULL,
  category     VARCHAR(50)  NOT NULL,
  total        DECIMAL(14,2) NOT NULL DEFAULT 0,
  rate         DECIMAL(10,4) NULL,
  note         VARCHAR(500) DEFAULT '',
  ship_status  ENUM('none','ordered','shipped','transit','delayed','arrived') NOT NULL DEFAULT 'ordered',
  ship_date    DATE NULL,
  eta_date     DATE NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_supplier (supplier_id),
  INDEX idx_name (name),
  INDEX idx_date (order_date),
  CONSTRAINT fk_orders_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payments (
  id          BIGINT PRIMARY KEY AUTO_INCREMENT,
  supplier_id BIGINT NOT NULL,
  order_id    BIGINT NULL,
  pay_date    DATE NOT NULL,
  amount      DECIMAL(14,2) NOT NULL,
  rate        DECIMAL(10,4) NULL,
  kind        ENUM('order','account','prepaid') NOT NULL DEFAULT 'order',
  note        VARCHAR(500) DEFAULT '',
  photo_path  VARCHAR(500) DEFAULT '',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_supplier (supplier_id),
  INDEX idx_order (order_id),
  INDEX idx_date (pay_date),
  CONSTRAINT fk_payments_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  CONSTRAINT fk_payments_order    FOREIGN KEY (order_id)    REFERENCES orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payment_allocations (
  id           BIGINT PRIMARY KEY AUTO_INCREMENT,
  payment_id   BIGINT NOT NULL,
  order_id     BIGINT NOT NULL,
  amount       DECIMAL(14,2) NOT NULL,
  allocated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_auto      TINYINT(1) NOT NULL DEFAULT 1,
  INDEX idx_payment (payment_id),
  INDEX idx_order (order_id),
  CONSTRAINT fk_alloc_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
  CONSTRAINT fk_alloc_order   FOREIGN KEY (order_id)   REFERENCES orders(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_photos (
  id          BIGINT PRIMARY KEY AUTO_INCREMENT,
  order_id    BIGINT NOT NULL,
  photo_path  VARCHAR(500) NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_order (order_id),
  CONSTRAINT fk_photos_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  k  VARCHAR(50) PRIMARY KEY,
  v  VARCHAR(200) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (k, v) VALUES ('rate', '65.5');

SET FOREIGN_KEY_CHECKS = 1;
