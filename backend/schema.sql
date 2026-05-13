-- 采购账款管理系统 — MySQL schema
-- Run: mysql -u root -p < schema.sql

CREATE DATABASE IF NOT EXISTS procurement
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE procurement;

CREATE TABLE IF NOT EXISTS orders (
  id           BIGINT PRIMARY KEY AUTO_INCREMENT,
  order_date   DATE         NOT NULL,
  name         VARCHAR(200) NOT NULL,
  category     VARCHAR(50)  NOT NULL,
  total        DECIMAL(14,2) NOT NULL DEFAULT 0,
  note         VARCHAR(500) DEFAULT '',
  ship_status  ENUM('none','ordered','shipped','transit','delayed','arrived') NOT NULL DEFAULT 'ordered',
  ship_date    DATE NULL,
  eta_date     DATE NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_name (name),
  INDEX idx_date (order_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
  id          BIGINT PRIMARY KEY AUTO_INCREMENT,
  order_id    BIGINT NOT NULL,
  pay_date    DATE NOT NULL,
  amount      DECIMAL(14,2) NOT NULL,
  note        VARCHAR(500) DEFAULT '',
  photo_path  VARCHAR(500) DEFAULT '',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  INDEX idx_order (order_id),
  INDEX idx_date (pay_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_photos (
  id          BIGINT PRIMARY KEY AUTO_INCREMENT,
  order_id    BIGINT NOT NULL,
  photo_path  VARCHAR(500) NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  INDEX idx_order (order_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
  k  VARCHAR(50) PRIMARY KEY,
  v  VARCHAR(200) NOT NULL
) ENGINE=InnoDB;

INSERT INTO settings (k, v) VALUES ('rate', '65.5')
  ON DUPLICATE KEY UPDATE v = v;
