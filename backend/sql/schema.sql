-- ============================================================
-- eMarket - Schéma de la base de données (MySQL / MariaDB)
-- Base : emarket
-- Encodage : utf8mb4
-- ============================================================

CREATE DATABASE IF NOT EXISTS emarket
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE emarket;

-- ------------------------------------------------------------
-- Utilisateurs (Administrateur / Gérant de stock / Caissier)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  email         VARCHAR(160) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('Administrateur','Gérant de stock','Caissier') NOT NULL DEFAULT 'Caissier',
  active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at    BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Catégories
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL UNIQUE,
  description TEXT,
  created_at  BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Produits
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(180) NOT NULL,
  ref           VARCHAR(60),
  category_name VARCHAR(120) DEFAULT '',
  unit          VARCHAR(40) NOT NULL DEFAULT 'unite',
  min_qty       INT NOT NULL DEFAULT 0,
  buy_price     DECIMAL(12,2) NOT NULL DEFAULT 0,
  sell_price    DECIMAL(12,2) NOT NULL DEFAULT 0,
  qty           INT NOT NULL DEFAULT 0,
  created_at    BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Mouvements de stock (entrées / sorties)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS movements (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  type       ENUM('in','out') NOT NULL,
  qty        INT NOT NULL,
  reason     VARCHAR(180),
  balance    INT NOT NULL,
  user_id    INT NULL,
  created_at BIGINT NOT NULL,
  INDEX idx_product (product_id),
  INDEX idx_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Sorties validées par le gérant (facturables chez le caissier)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS validated_exits (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  move_id    INT NOT NULL,
  product_id INT NOT NULL,
  qty        INT NOT NULL,
  used       INT NOT NULL DEFAULT 0,
  reason     VARCHAR(180),
  created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Clients
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clients (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(150) NOT NULL,
  phone      VARCHAR(40),
  email      VARCHAR(160),
  address    VARCHAR(255),
  created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Devis
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS quotes (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  num         VARCHAR(40) NOT NULL,
  client_name VARCHAR(150),
  date        BIGINT NOT NULL,
  status      VARCHAR(20) NOT NULL DEFAULT 'envoye',
  total       DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at  BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quote_items (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  quote_id   INT NOT NULL,
  product_id INT NULL,
  name       VARCHAR(180) NOT NULL,
  qty        INT NOT NULL,
  price      DECIMAL(12,2) NOT NULL,
  INDEX idx_quote (quote_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Factures
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoices (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  num         VARCHAR(40) NOT NULL,
  client_name VARCHAR(150),
  date        BIGINT NOT NULL,
  total       DECIMAL(12,2) NOT NULL DEFAULT 0,
  paid        DECIMAL(12,2) NOT NULL DEFAULT 0,
  source      VARCHAR(20) NOT NULL DEFAULT 'facture',
  created_at  BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_items (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id        INT NOT NULL,
  product_id        INT NULL,
  validated_exit_id INT NULL,
  name              VARCHAR(180) NOT NULL,
  qty               INT NOT NULL,
  price             DECIMAL(12,2) NOT NULL,
  INDEX idx_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Paiements
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id  INT NOT NULL,
  client_name VARCHAR(150),
  amount      DECIMAL(12,2) NOT NULL,
  method      VARCHAR(40) NOT NULL DEFAULT 'Especes',
  created_at  BIGINT NOT NULL,
  INDEX idx_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Journal d'activité
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_name  VARCHAR(120),
  action     VARCHAR(120),
  detail     TEXT,
  created_at BIGINT NOT NULL,
  INDEX idx_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DONNÉES DE DÉMONSTRATION (seed)
-- ============================================================
-- Mot de passe de tous les comptes de démo : 123456
-- (hash bcrypt généré pour '123456')

INSERT INTO users (name, email, password_hash, role, active, created_at) VALUES
('Administrateur', 'admin@emarket.com', '$2y$10$4supPMhVtars.UZy49iFH.b0MT39QYzeksELclcNuKFPOrGnvdBC2', 'Administrateur', 1, UNIX_TIMESTAMP()*1000 - 3000),
('Gérant de stock', 'gerant@emarket.com', '$2y$10$4supPMhVtars.UZy49iFH.b0MT39QYzeksELclcNuKFPOrGnvdBC2', 'Gérant de stock', 1, UNIX_TIMESTAMP()*1000 - 2000),
('Caissier', 'caissier@emarket.com', '$2y$10$4supPMhVtars.UZy49iFH.b0MT39QYzeksELclcNuKFPOrGnvdBC2', 'Caissier', 1, UNIX_TIMESTAMP()*1000 - 1000);

INSERT INTO categories (name, description, created_at) VALUES
('Alimentation', 'Produits alimentaires et denrées', UNIX_TIMESTAMP()*1000 - 4000),
('Hygiène', "Produits d'entretien et d'hygiène", UNIX_TIMESTAMP()*1000 - 3000),
('Fournitures', 'Fournitures de bureau et scolaires', UNIX_TIMESTAMP()*1000 - 2000),
('Boissons', 'Boissons et eaux', UNIX_TIMESTAMP()*1000 - 1000);

INSERT INTO products (name, ref, category_name, unit, min_qty, buy_price, sell_price, qty, created_at) VALUES
('Riz parfumé 5kg', 'REF-001', 'Alimentation', 'sachet', 10, 4200, 5200, 0, UNIX_TIMESTAMP()*1000 - 10000),
('Huile végétale 1L', 'REF-002', 'Alimentation', 'litre', 10, 1400, 1800, 24, UNIX_TIMESTAMP()*1000 - 9000),
('Savon liquide 500ml', 'REF-003', 'Hygiène', 'unite', 8, 900, 1300, 5, UNIX_TIMESTAMP()*1000 - 8000),
('Sucre en poudre 1kg', 'REF-004', 'Alimentation', 'sachet', 20, 950, 1250, 45, UNIX_TIMESTAMP()*1000 - 7000),
('Cahier 100 pages', 'REF-005', 'Fournitures', 'unite', 15, 350, 550, 3, UNIX_TIMESTAMP()*1000 - 6000),
('Lait en poudre 400g', 'REF-006', 'Alimentation', 'sachet', 5, 1600, 2100, 12, UNIX_TIMESTAMP()*1000 - 5000),
('Eau minérale 1,5L', 'REF-007', 'Boissons', 'unite', 24, 250, 400, 60, UNIX_TIMESTAMP()*1000 - 4000),
('Pâtes alimentaires 500g', 'REF-008', 'Alimentation', 'sachet', 12, 350, 550, 0, UNIX_TIMESTAMP()*1000 - 3000),
('Stylo bleu', 'REF-009', 'Fournitures', 'unite', 30, 100, 200, 80, UNIX_TIMESTAMP()*1000 - 2000),
('Détergent 1L', 'REF-010', 'Hygiène', 'litre', 10, 1100, 1500, 6, UNIX_TIMESTAMP()*1000 - 1000);

INSERT INTO movements (product_id, type, qty, reason, balance, created_at) VALUES
(2, 'in', 24, 'Stock initial', 24, UNIX_TIMESTAMP()*1000 - 86400000*2),
(7, 'in', 60, 'Stock initial', 60, UNIX_TIMESTAMP()*1000 - 86400000*2),
(4, 'in', 45, 'Stock initial', 45, UNIX_TIMESTAMP()*1000 - 86400000),
(9, 'in', 80, 'Stock initial', 80, UNIX_TIMESTAMP()*1000 - 86400000),
(3, 'out', 8, 'Vente client', 5, UNIX_TIMESTAMP()*1000 - 3600000*5);

INSERT INTO validated_exits (move_id, product_id, qty, used, reason, created_at) VALUES
(5, 3, 8, 0, 'Vente client', UNIX_TIMESTAMP()*1000 - 3600000*5);

INSERT INTO clients (name, phone, email, address, created_at) VALUES
('Jean Kossi', '+229 97 00 00 00', 'jean@entreprise.com', 'Cotonou', UNIX_TIMESTAMP()*1000 - 3000),
('Awa Diallo', '+229 96 00 00 00', 'awa@gmail.com', 'Cotonou', UNIX_TIMESTAMP()*1000 - 2000),
('Mariam Traoré', '+229 95 00 00 00', 'mariam@gmail.com', 'Cotonou', UNIX_TIMESTAMP()*1000 - 1000);

INSERT INTO quotes (num, client_name, date, status, total, created_at) VALUES
('DEV-001', 'Jean Kossi', UNIX_TIMESTAMP()*1000 - 86400000*3, 'envoye', 15200, UNIX_TIMESTAMP()*1000 - 86400000*3),
('DEV-002', 'Mariam Traoré', UNIX_TIMESTAMP()*1000 - 86400000, 'brouillon', 6250, UNIX_TIMESTAMP()*1000 - 86400000);

INSERT INTO quote_items (quote_id, product_id, name, qty, price) VALUES
(1, 1, 'Riz parfumé 5kg', 2, 5200),
(1, 7, 'Eau minérale 1,5L', 12, 400),
(2, 4, 'Sucre en poudre 1kg', 5, 1250);

INSERT INTO invoices (num, client_name, date, total, paid, source, created_at) VALUES
('FACT-001', 'Awa Diallo', UNIX_TIMESTAMP()*1000 - 86400000*4, 12700, 0, 'facture', UNIX_TIMESTAMP()*1000 - 86400000*4),
('FACT-002', 'Jean Kossi', UNIX_TIMESTAMP()*1000 - 86400000*2, 21100, 15000, 'facture', UNIX_TIMESTAMP()*1000 - 86400000*2),
('FACT-003', 'Mariam Traoré', UNIX_TIMESTAMP()*1000, 5000, 0, 'facture', UNIX_TIMESTAMP()*1000);

INSERT INTO invoice_items (invoice_id, product_id, validated_exit_id, name, qty, price) VALUES
(1, 2, NULL, 'Huile végétale 1L', 4, 1800),
(1, 5, NULL, 'Cahier 100 pages', 10, 550),
(2, 1, NULL, 'Riz parfumé 5kg', 3, 5200),
(2, 8, NULL, 'Pâtes alimentaires 500g', 10, 550),
(3, 9, NULL, 'Stylo bleu', 25, 200);

INSERT INTO payments (invoice_id, client_name, amount, method, created_at) VALUES
(2, 'Jean Kossi', 15000, 'Mobile Money', UNIX_TIMESTAMP()*1000 - 86400000*2);

INSERT INTO activity (user_name, action, detail, created_at) VALUES
('Administrateur', 'Installation', 'Base de données initialisée avec les données de démonstration.', UNIX_TIMESTAMP()*1000 - 3600000*2),
('Gérant de stock', 'Sortie de stock', 'Sortie validée de 8 Savon liquide 500ml pour Vente client.', UNIX_TIMESTAMP()*1000 - 3600000*5);
