-- ============================================================
--  TMS  —  database.sql   (Transport Management System schema)
--  Fresh-install schema + starter data. Safe to run on an EMPTY database.
--  Easiest way to run it: open /install.php after creating an empty
--  database and filling in config.local.php. (Or import it in phpMyAdmin.)
--  PHP 7.4+/8.x · MySQL 5.7+/MariaDB 10.3+ · utf8mb4 / InnoDB.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+02:00";


-- ------------------------------------------------------------
-- 1) SETTINGS  (single source of truth — every value editable)
--    master_only = 1 means only the Master role may change it.
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `setting_key`   VARCHAR(80)  NOT NULL,
  `setting_value` VARCHAR(255) NOT NULL,
  `value_type`    ENUM('number','percent','money','text') NOT NULL DEFAULT 'text',
  `category`      VARCHAR(60)  NOT NULL DEFAULT 'general',
  `label`         VARCHAR(150) NOT NULL,
  `master_only`   TINYINT(1)   NOT NULL DEFAULT 0,
  `updated_at`    DATETIME     NULL DEFAULT NULL,
  `updated_by`    INT          NULL DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`setting_key`,`setting_value`,`value_type`,`category`,`label`,`master_only`) VALUES
-- Company identity (auto-fills invoices / statements)
('company_name','CyberEye Consulting','text','company','Company Name',0),
('company_address','35 Kensington Close, Parklands, Cape Town, Western Cape, 7441, South Africa','text','company','Registered Address',0),
('company_reg','2020/566486/07','text','company','Registration No.',0),
('company_vat','4570315764','text','company','VAT No.',0),
('company_email','','text','company','Email',0),
('company_phone','','text','company','Phone',0),
('company_bank','First National Bank (FNB)','text','company','Bank',0),
('company_account','62860049264','text','company','Account No.',0),
('company_tagline','Consulting & Rigging','text','company','Tagline',0),
('brand_color','#2B63B8','text','company','Brand colour (hex, e.g. #C19A3E)',0),
('company_motto','The power to lift. The capacity to move.','text','company','Motto',0),
('bank_branch_code','200909','text','company','Branch code',0),
('bank_account_type','','text','company','Account type',0),
('invoice_terms','','long','invoice','Invoice terms / note',0),
-- Crane truck (call-out + 3-man crew hire)
('crane_truck_reg','','text','crane','Crane truck registration',0),
('crane_consumption','40','number','crane','Crane diesel consumption (L/100km)',0),
('crane_running_cost','5.50','money','crane','Crane running cost (R/km)',0),
('crane_crew_rate','1750.00','money','crane','3-man crew rate (R per hour)',0),
('crane_min_hours','2','number','crane','Minimum crane hire (hours)',0),
('crane_callout_markup','0','percent','crane','Call-out mark-up on cost (%)',0),
('crane_due_days','0','number','crane','Invoice due after (days, 0 = on receipt)',0),
('crane_quote_prefix','QU-','text','crane','Quote number prefix',0),
('crane_quote_next','12','number','crane','Next quote number',0),
('crane_invoice_prefix','INV-','text','crane','Invoice number prefix',0),
('crane_invoice_next','158','number','crane','Next invoice number',0),
-- Fuel & cost model (master-key)
('diesel_price','28.00','money','cost','Diesel Price (R/L)',1),
('consumption_empty','26','number','cost','Consumption Empty (L/100km)',1),
('consumption_loaded','38','number','cost','Consumption Loaded (L/100km)',1),
('consumption_genset','44','number','cost','Consumption + Genset (L/100km)',1),
('running_cost','5.50','money','cost','Running Cost (R/km)',1),
('driver_day_cost','1000.00','money','cost','Driver Day Cost (R)',1),
('genset_wear','350.00','money','cost','Genset Wear per job (R)',1),
-- Commission (master-key)
('commission_6x4','10','percent','commission','Commission 6x4 Fullbox (%)',1),
('commission_6x2','4','percent','commission','Commission 6x2 Empty-only (%)',1),
-- Tax (master-key)
('vat_pct','15','percent','tax','VAT (%)',1),
-- Client rate mechanisms
-- Genset pay per client
-- Standing / waiting rate per client (per hour)
-- Free-hour windows by location (hours)
('free_hours_depot','2','number','rates','Free Hours — Depot/Port',0),
('free_hours_packstore','2','number','rates','Free Hours — Packstore',0),
('free_hours_client','1','number','rates','Free Hours — Client Premises',0),
-- Dual-load on-route flat fees
-- Empty container 6m factor (6M = 12M × this)
('empty_6m_factor','0.5','number','rates','6M Empty = 12M × this factor',0),
-- Surcharges (editable line-item add-ons)
-- System
('ref_prefix','RT','text','system','Trip Reference Prefix',0),
('low_margin_threshold','15','percent','system','Low-Margin Alert (%)',0),
('expiry_alert_days','30','number','system','Expiry Alert Window (days)',0);

-- ------------------------------------------------------------
-- 2) CLIENTS  (billing clients + shipping lines / cargo clients)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `clients`;
CREATE TABLE `clients` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(120) NOT NULL,
  `code`        VARCHAR(30)  NOT NULL,
  `client_type` ENUM('billing','shipping_line') NOT NULL DEFAULT 'billing',
  `mechanism`   ENUM('levy','flat','markup','none') NOT NULL DEFAULT 'none',
  -- levy/markup: % applied on top of the base route rate, genset and flagged surcharges.
  `adjust_pct`  DECIMAL(6,2)  NOT NULL DEFAULT 0.00,
  `genset_base` DECIMAL(10,2) NOT NULL DEFAULT 0.00,   -- genset charge before the % adjustment
  `standing_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00, -- R per chargeable standing hour
  `free_hours`  DECIMAL(6,2)  NOT NULL DEFAULT 0.00,   -- hours before standing is charged
  `dual_flat`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,   -- dual on-route flat amount (reference)
  `to_required` TINYINT(1)    NOT NULL DEFAULT 0,      -- a T.O / order number is mandatory on trips
  `invoice_layout` ENUM('A','B') NOT NULL DEFAULT 'B', -- A = container-led, B = haulier-led
  `active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_client_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `clients`
 (`name`,`code`,`client_type`,`mechanism`,`adjust_pct`,`genset_base`,`standing_rate`,`free_hours`,`dual_flat`,`to_required`,`invoice_layout`) VALUES
('Bolster','bolster','billing','flat',0.00,1550.00,350.00,4,800.00,0,'A'),
('TLC','tlc','billing','levy',-3.51,0.00,0.00,0,0.00,0,'B'),
('DCP','dcp','billing','flat',0.00,0.00,0.00,0,0.00,0,'B'),
('Grindrod','grindrod','billing','flat',0.00,0.00,0.00,0,0.00,0,'B'),
('Coetzee','coetzee','billing','flat',0.00,0.00,0.00,0,0.00,0,'B'),
('Deoxy','deoxy','billing','flat',0.00,0.00,0.00,0,0.00,0,'B'),
('Maximize','maximize','billing','flat',0.00,0.00,0.00,0,0.00,0,'B'),
('Limecoal','limecoal','billing','flat',0.00,0.00,0.00,0,0.00,0,'B'),
('CMA CGM','cma','shipping_line','none',0,0,0,0,0,0,'B'),
('MSC','msc','shipping_line','none',0,0,0,0,0,0,'B'),
('COSCO','cos','shipping_line','none',0,0,0,0,0,0,'B'),
('Maersk','maersk','shipping_line','none',0,0,0,0,0,0,'B'),
('Hapag-Lloyd','hapag','shipping_line','none',0,0,0,0,0,0,'B'),
('ONE','one','shipping_line','none',0,0,0,0,0,0,'B'),
('PIL','pil','shipping_line','none',0,0,0,0,0,0,'B');

-- Per-client optional surcharges offered when quoting.
-- adjust = 1 -> the client's % (levy/markup) is applied to the amount.
DROP TABLE IF EXISTS `client_surcharges`;
CREATE TABLE `client_surcharges` (
  `id`        INT AUTO_INCREMENT PRIMARY KEY,
  `client_id` INT NOT NULL,
  `label`     VARCHAR(120)  NOT NULL,
  `amount`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `adjust`    TINYINT(1)    NOT NULL DEFAULT 0,
  `active`    TINYINT(1)    NOT NULL DEFAULT 1,
  KEY `ix_cs_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `client_surcharges` (`client_id`,`label`,`amount`,`adjust`)
SELECT id,'Empty depot diversion',450.00,0 FROM clients WHERE code='bolster'
UNION ALL SELECT id,'Overstay boxes (Port to DCP & Zackpack)',3500.00,0 FROM clients WHERE code='bolster'
UNION ALL SELECT id,'Genset cross haul',1150.00,0 FROM clients WHERE code='bolster'
UNION ALL SELECT id,'Dual load on route',800.00,0 FROM clients WHERE code='bolster';

-- ------------------------------------------------------------
-- 3) DESTINATIONS  (route list + km; client rates live in client_rates)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `destinations`;
CREATE TABLE `destinations` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `name`         VARCHAR(120)  NOT NULL,
  `km`           INT           NOT NULL DEFAULT 0,
  `active`       TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_dest_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `destinations` (`name`,`km`) VALUES
('Killarney Paardeneiland',12),
('Epping',17),
('Airport Industria',34),
('Belville',40),
('Stikland',45),
('Blackheath',50),
('Fisantekraal',70),
('Stellenbosch',95),
('Somerset West',99),
('Simondium',125),
('Paarl',130),
('Wellington',148),
('Grabouw',145),
('Franschhoek',155),
('Villiersdorp',195),
('Gouda',214),
('Ceres (Op Die Berg)',211),
('Wolseley',223),
('Tulbagh',241),
('Worcester',244),
('Saron',245),
('Bet-El',245),
('Ceres',255),
('Prince Alfred Hamlet',276),
('Hexriver Valley',290),
('Porterville',293),
('Redelinghuys',298),
('Piketberg',299),
('Ashton',380),
('Montagu',393),
('Robertson',395),
('Citrusdal',402),
('Swellendam',466),
('Clanwilliam',505),
('Tradouw',521),
('Barrydale',530),
('Trawal',578),
('Klawer',609),
('Vredendal',641),
('Montague Gardens',30),
('Durbanville',60),
('Hout Bay',55),
('Retreat',50),
('Riebeek Kasteel',150),
('Saldanha',265),
('Touws Rivier',385),
('Cederberg',450),
('Ceres Heavy',255),
('Ceres CFG / CCS',255),
('Piketberg (Hoochland)',299),
('Bolster cross haul',30),
('Combi / CTCT to FPT (transhipment)',20),
('DCP cross haul (DCP to Port)',40),
('Two Oceans Cold Store (from DCP)',50),
('Paarden Eiland to Port (full)',25);

-- Base route rate per billing client. No row = the client does not serve that route.
DROP TABLE IF EXISTS `client_rates`;
CREATE TABLE `client_rates` (
  `client_id`      INT NOT NULL,
  `destination_id` INT NOT NULL,
  `base_rate`      DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (`client_id`,`destination_id`),
  KEY `ix_cr_dest` (`destination_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `client_rates` (`client_id`,`destination_id`,`base_rate`)
SELECT c.id, d.id, v.r FROM clients c JOIN (
SELECT 'Airport Industria' AS n, 4005.00 AS r
UNION ALL
SELECT 'Barrydale' AS n, 13351.00 AS r
UNION ALL
SELECT 'Belville' AS n, 4005.00 AS r
UNION ALL
SELECT 'Blackheath' AS n, 4005.00 AS r
UNION ALL
SELECT 'Ceres' AS n, 8780.00 AS r
UNION ALL
SELECT 'Ceres (Op Die Berg)' AS n, 10317.00 AS r
UNION ALL
SELECT 'Citrusdal' AS n, 10317.00 AS r
UNION ALL
SELECT 'Epping' AS n, 4005.00 AS r
UNION ALL
SELECT 'Fisantekraal' AS n, 4369.00 AS r
UNION ALL
SELECT 'Franschhoek' AS n, 5340.00 AS r
UNION ALL
SELECT 'Gouda' AS n, 7768.00 AS r
UNION ALL
SELECT 'Grabouw' AS n, 5619.00 AS r
UNION ALL
SELECT 'Hexriver Valley' AS n, 8450.00 AS r
UNION ALL
SELECT 'Killarney Paardeneiland' AS n, 4005.00 AS r
UNION ALL
SELECT 'Montagu' AS n, 10317.00 AS r
UNION ALL
SELECT 'Paarl' AS n, 5340.00 AS r
UNION ALL
SELECT 'Prince Alfred Hamlet' AS n, 8496.00 AS r
UNION ALL
SELECT 'Robertson' AS n, 9650.00 AS r
UNION ALL
SELECT 'Saron' AS n, 8450.00 AS r
UNION ALL
SELECT 'Simondium' AS n, 5340.00 AS r
UNION ALL
SELECT 'Swellendam' AS n, 13351.00 AS r
UNION ALL
SELECT 'Trawal' AS n, 14261.00 AS r
UNION ALL
SELECT 'Tulbagh' AS n, 8450.00 AS r
UNION ALL
SELECT 'Villiersdorp' AS n, 6381.00 AS r
UNION ALL
SELECT 'Wellington' AS n, 5462.00 AS r
UNION ALL
SELECT 'Wolseley' AS n, 8450.00 AS r
UNION ALL
SELECT 'Worcester' AS n, 8450.00 AS r
UNION ALL
SELECT 'Bolster cross haul' AS n, 2050.00 AS r
UNION ALL
SELECT 'Combi / CTCT to FPT (transhipment)' AS n, 1250.00 AS r
) v JOIN destinations d ON d.name = v.n WHERE c.code = 'bolster';

INSERT INTO `client_rates` (`client_id`,`destination_id`,`base_rate`)
SELECT c.id, d.id, v.r FROM clients c JOIN (
SELECT 'Airport Industria' AS n, 4628.00 AS r
UNION ALL
SELECT 'Belville' AS n, 5057.00 AS r
UNION ALL
SELECT 'Montague Gardens' AS n, 2969.00 AS r
UNION ALL
SELECT 'Killarney Paardeneiland' AS n, 4463.00 AS r
UNION ALL
SELECT 'Epping' AS n, 4463.00 AS r
UNION ALL
SELECT 'Ashton' AS n, 12326.00 AS r
UNION ALL
SELECT 'Barrydale' AS n, 17020.00 AS r
UNION ALL
SELECT 'Cederberg' AS n, 12968.00 AS r
UNION ALL
SELECT 'Ceres' AS n, 9593.00 AS r
UNION ALL
SELECT 'Ceres Heavy' AS n, 10418.00 AS r
UNION ALL
SELECT 'Ceres CFG / CCS' AS n, 10055.00 AS r
UNION ALL
SELECT 'Prince Alfred Hamlet' AS n, 10815.00 AS r
UNION ALL
SELECT 'Ceres (Op Die Berg)' AS n, 12116.00 AS r
UNION ALL
SELECT 'Citrusdal' AS n, 11810.00 AS r
UNION ALL
SELECT 'Clanwilliam' AS n, 14452.00 AS r
UNION ALL
SELECT 'Durbanville' AS n, 4828.00 AS r
UNION ALL
SELECT 'Franschhoek' AS n, 6632.00 AS r
UNION ALL
SELECT 'Gouda' AS n, 7880.00 AS r
UNION ALL
SELECT 'Grabouw' AS n, 6311.00 AS r
UNION ALL
SELECT 'Hexriver Valley' AS n, 10101.00 AS r
UNION ALL
SELECT 'Hout Bay' AS n, 6078.00 AS r
UNION ALL
SELECT 'Klawer' AS n, 16322.00 AS r
UNION ALL
SELECT 'Montagu' AS n, 13642.00 AS r
UNION ALL
SELECT 'Paarl' AS n, 5546.00 AS r
UNION ALL
SELECT 'Piketberg (Hoochland)' AS n, 11363.00 AS r
UNION ALL
SELECT 'Piketberg' AS n, 9672.00 AS r
UNION ALL
SELECT 'Porterville' AS n, 10124.00 AS r
UNION ALL
SELECT 'Retreat' AS n, 5377.00 AS r
UNION ALL
SELECT 'Riebeek Kasteel' AS n, 7885.00 AS r
UNION ALL
SELECT 'Robertson' AS n, 10799.00 AS r
UNION ALL
SELECT 'Saldanha' AS n, 10524.00 AS r
UNION ALL
SELECT 'Simondium' AS n, 5320.00 AS r
UNION ALL
SELECT 'Somerset West' AS n, 5613.00 AS r
UNION ALL
SELECT 'Stellenbosch' AS n, 5320.00 AS r
UNION ALL
SELECT 'Swellendam' AS n, 14595.00 AS r
UNION ALL
SELECT 'Touws Rivier' AS n, 11363.00 AS r
UNION ALL
SELECT 'Trawal' AS n, 16110.00 AS r
UNION ALL
SELECT 'Tulbagh' AS n, 9838.00 AS r
UNION ALL
SELECT 'Villiersdorp' AS n, 8417.00 AS r
UNION ALL
SELECT 'Vredendal' AS n, 16841.00 AS r
UNION ALL
SELECT 'Wellington' AS n, 5518.00 AS r
UNION ALL
SELECT 'Wolseley' AS n, 9194.00 AS r
UNION ALL
SELECT 'Worcester' AS n, 9489.00 AS r
) v JOIN destinations d ON d.name = v.n WHERE c.code = 'tlc';

INSERT INTO `client_rates` (`client_id`,`destination_id`,`base_rate`)
SELECT c.id, d.id, v.r FROM clients c JOIN (
SELECT 'Grabouw' AS n, 5000.00 AS r
UNION ALL
SELECT 'DCP cross haul (DCP to Port)' AS n, 2300.00 AS r
UNION ALL
SELECT 'Two Oceans Cold Store (from DCP)' AS n, 3300.00 AS r
) v JOIN destinations d ON d.name = v.n WHERE c.code = 'dcp';

INSERT INTO `client_rates` (`client_id`,`destination_id`,`base_rate`)
SELECT c.id, d.id, v.r FROM clients c JOIN (
SELECT 'Paarden Eiland to Port (full)' AS n, 1100.00 AS r
) v JOIN destinations d ON d.name = v.n WHERE c.code = 'grindrod';






-- ------------------------------------------------------------
-- 4) SUBCONTRACTORS
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `subcontractors`;
CREATE TABLE `subcontractors` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `name`             VARCHAR(120) NOT NULL,
  `contact_person`   VARCHAR(120) NULL,
  `phone`            VARCHAR(40)  NULL,
  `email`            VARCHAR(150) NULL,
  `vat_registered`   TINYINT(1)   NOT NULL DEFAULT 0,
  `dedicated`        TINYINT(1)   NOT NULL DEFAULT 0,
  `pay_visibility`   TINYINT(1)   NOT NULL DEFAULT 1,
  `expense_capture`  TINYINT(1)   NOT NULL DEFAULT 1,
  `banking_notes`    VARCHAR(255) NULL,
  `status`           ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by`       INT          NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5) TRUCKS
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `trucks`;
CREATE TABLE `trucks` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `registration`     VARCHAR(30)  NOT NULL,
  `truck_type`       ENUM('6x4','6x2') NOT NULL DEFAULT '6x4',
  `owner_type`       ENUM('own','subcontractor') NOT NULL DEFAULT 'own',
  `subcontractor_id` INT          NULL,
  `size_capability`  ENUM('6m','12m','both') NOT NULL DEFAULT 'both',
  `has_genset`       TINYINT(1)   NOT NULL DEFAULT 0,
  `odometer`         INT          NULL DEFAULT 0,
  `licence_expiry`   DATE         NULL,
  `insurance_expiry` DATE         NULL,
  `service_expiry`   DATE         NULL,
  `status`           ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_truck_reg` (`registration`),
  KEY `ix_truck_sub` (`subcontractor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 6) DRIVERS
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `drivers`;
CREATE TABLE `drivers` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `name`             VARCHAR(120) NOT NULL,
  `licence_no`       VARCHAR(60)  NULL,
  `licence_expiry`   DATE         NULL,
  `phone`            VARCHAR(40)  NULL,
  `subcontractor_id` INT          NULL,  -- NULL = own driver
  `pay_per_trip`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `bank_name`        VARCHAR(80)  NULL,
  `account_no`       VARCHAR(40)  NULL,
  `branch_code`      VARCHAR(20)  NULL,
  `account_holder`   VARCHAR(120) NULL,
  `expense_capture`  TINYINT(1)   NOT NULL DEFAULT 0,
  `pay_visibility`   TINYINT(1)   NOT NULL DEFAULT 1,
  `status`           ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_driver_sub` (`subcontractor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7) USERS  (logins / roles)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `name`             VARCHAR(120) NOT NULL,
  `email`            VARCHAR(150) NOT NULL,
  `password_hash`    VARCHAR(255) NOT NULL,
  `totp_secret`      VARCHAR(64)  NULL,
  `totp_enabled`     TINYINT(1)   NOT NULL DEFAULT 0,
  `totp_recovery`    TEXT         NULL,
  `role`             ENUM('master','admin','finance','subcontractor','driver','viewer') NOT NULL DEFAULT 'viewer',
  `subcontractor_id` INT          NULL,
  `driver_id`        INT          NULL,
  `status`           ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `last_login`       DATETIME     NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by`       INT          NULL,
  UNIQUE KEY `uq_user_email` (`email`),
  KEY `ix_user_sub` (`subcontractor_id`),
  KEY `ix_user_driver` (`driver_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- No user is seeded. install.php creates the master account.

-- ------------------------------------------------------------
-- 8) TRIPS  (the core operational record)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `trips`;
CREATE TABLE `trips` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `trip_ref`          VARCHAR(30)  NOT NULL,
  `collection_date`   DATE         NULL,
  `loading_date`      DATE         NULL,
  `delivery_date`     DATE         NULL,
  `status`            ENUM('Draft','Scheduled','In Transit','Delivered','Invoiced','Paid','Cancelled','On Hold') NOT NULL DEFAULT 'Draft',
  `billing_client_id` INT          NULL,
  `shipping_line_id`  INT          NULL,
  `to_number`         VARCHAR(60)  NULL,
  `subcontractor_id`  INT          NULL,  -- NULL = own truck
  `truck_id`          INT          NULL,
  `driver_id`         INT          NULL,
  `container_number`  VARCHAR(20)  NULL,
  `container_size`    ENUM('6m','12m') NULL,
  `load_state`        ENUM('FULL','EMPTY') NULL,
  `job_type`          ENUM('empty','crosshaul','packing','unpacking','combi-dual','standing') NULL,
  `origin`            VARCHAR(120) NULL,
  `destination_id`    INT          NULL,
  `destination_2_id`  INT          NULL,  -- for dual loads
  `route`             VARCHAR(180) NULL,
  `km`                INT          NULL DEFAULT 0,
  `has_genset`        TINYINT(1)   NOT NULL DEFAULT 0,
  `standing_hrs`      DECIMAL(6,2) NOT NULL DEFAULT 0,
  `is_dual`           TINYINT(1)   NOT NULL DEFAULT 0,
  `dual_mode`         ENUM('on_route','off_route') NULL,
  -- money (auto-computed by the engine, rate is editable):
  `rate`             DECIMAL(12,2) NOT NULL DEFAULT 0,
  `genset_amount`    DECIMAL(12,2) NOT NULL DEFAULT 0,
  `standing_amount`  DECIMAL(12,2) NOT NULL DEFAULT 0,
  `surcharge_total`  DECIMAL(12,2) NOT NULL DEFAULT 0,
  `gross_revenue`    DECIMAL(12,2) NOT NULL DEFAULT 0,
  `commission`       DECIMAL(12,2) NOT NULL DEFAULT 0,
  `subbie_payout`    DECIMAL(12,2) NOT NULL DEFAULT 0,
  `driver_pay`       DECIMAL(12,2) NOT NULL DEFAULT 0,
  `est_cost`         DECIMAL(12,2) NOT NULL DEFAULT 0,
  `net_profit`       DECIMAL(12,2) NOT NULL DEFAULT 0,
  `margin_pct`       DECIMAL(6,2)  NOT NULL DEFAULT 0,
  -- billing
  `invoice_id`        INT          NULL,
  `payment_status`    ENUM('unpaid','part','paid') NOT NULL DEFAULT 'unpaid',
  `amount_paid`       DECIMAL(12,2) NOT NULL DEFAULT 0,
  `outstanding`       DECIMAL(12,2) NOT NULL DEFAULT 0,
  -- proof / notes / workflow
  `pod_path`          VARCHAR(255) NULL,
  -- POD collection: 'not_collected' = still with driver; 'collected' = manager has it (ready to submit to client)
  `pod_collected`     ENUM('not_collected','collected') NOT NULL DEFAULT 'not_collected',
  `pod_collected_at`  DATETIME     NULL,
  `pod_collected_by`  INT          NULL,
  `notes`             TEXT         NULL,
  `flagged`           TINYINT(1)   NOT NULL DEFAULT 0,
  `flag_note`         VARCHAR(255) NULL,
  -- Phase 2 room (do not build yet): live location
  `live_lat`          DECIMAL(10,6) NULL,
  `live_lng`          DECIMAL(10,6) NULL,
  -- audit
  `created_by`        INT          NULL,
  `modified_by`       INT          NULL,
  `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_at`       DATETIME     NULL,
  UNIQUE KEY `uq_trip_ref` (`trip_ref`),
  KEY `ix_trip_client` (`billing_client_id`),
  KEY `ix_trip_status` (`status`),
  KEY `ix_trip_sub` (`subcontractor_id`),
  KEY `ix_trip_truck` (`truck_id`),
  KEY `ix_trip_driver` (`driver_id`),
  KEY `ix_trip_container` (`container_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 9) TRIP SURCHARGES  (selected line-item add-ons per trip)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `trip_surcharges`;
CREATE TABLE `trip_surcharges` (
  `id`        INT AUTO_INCREMENT PRIMARY KEY,
  `trip_id`   INT NOT NULL,
  `label`     VARCHAR(120)  NOT NULL,
  `amount`    DECIMAL(12,2) NOT NULL DEFAULT 0,
  KEY `ix_ts_trip` (`trip_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 10) INVOICES
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `invoices`;
CREATE TABLE `invoices` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `invoice_number` VARCHAR(40)  NOT NULL,
  `client_id`      INT          NOT NULL,
  `period_start`   DATE         NULL,
  `period_end`     DATE         NULL,
  `period_label`   VARCHAR(60)  NULL,
  `subtotal`       DECIMAL(12,2) NOT NULL DEFAULT 0,
  `vat_amount`     DECIMAL(12,2) NOT NULL DEFAULT 0,
  `total`          DECIMAL(12,2) NOT NULL DEFAULT 0,
  `status`         ENUM('draft','sent','paid','part') NOT NULL DEFAULT 'draft',
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by`     INT          NULL,
  UNIQUE KEY `uq_invoice_number` (`invoice_number`),
  KEY `ix_inv_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 11) PAYMENTS
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `payments`;
CREATE TABLE `payments` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `invoice_id`  INT NOT NULL,
  `amount`      DECIMAL(12,2) NOT NULL DEFAULT 0,
  `paid_on`     DATE NOT NULL,
  `method`      VARCHAR(60) NULL,
  `notes`       VARCHAR(255) NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by`  INT NULL,
  KEY `ix_pay_inv` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 12) DRIVER EXPENSES  (feeds fuel reconciliation)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `driver_expenses`;
CREATE TABLE `driver_expenses` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `driver_id`  INT NOT NULL,
  `truck_id`   INT NULL,
  `trip_id`    INT NULL,
  `type`       ENUM('diesel','toll','repair','other') NOT NULL DEFAULT 'diesel',
  `litres`     DECIMAL(8,2) NULL,
  `amount`     DECIMAL(12,2) NOT NULL DEFAULT 0,
  `odometer`   INT NULL,
  `station`    VARCHAR(120) NULL,
  `spent_on`   DATE NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_exp_driver` (`driver_id`),
  KEY `ix_exp_truck` (`truck_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 14) SETTINGS CHANGE HISTORY
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `settings_history`;
CREATE TABLE `settings_history` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(80) NOT NULL,
  `old_value`   VARCHAR(255) NULL,
  `new_value`   VARCHAR(255) NULL,
  `changed_by`  INT NULL,
  `changed_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 15) AUDIT LOG
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `audit_log`;
CREATE TABLE `audit_log` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT NULL,
  `action`      VARCHAR(60) NOT NULL,
  `entity`      VARCHAR(60) NOT NULL,
  `entity_id`   INT NULL,
  `detail`      VARCHAR(255) NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_audit_user` (`user_id`),
  KEY `ix_audit_entity` (`entity`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `login_attempts` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `email`        VARCHAR(190) NULL,
  `ip`           VARCHAR(45)  NULL,
  `success`      TINYINT(1)   NOT NULL DEFAULT 0,
  `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_la_email` (`email`),
  KEY `ix_la_ip` (`ip`),
  KEY `ix_la_time` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- 16) EMPTY RATES  (empty-container rate book)
--     Keyed by carrier/line + from -> to. Store the 12M rate;
--     6M is derived as 12M × empty_6m_factor (default 0.5).
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `empty_rates`;
CREATE TABLE `empty_rates` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `carrier`       VARCHAR(80)  NOT NULL,
  `from_location` VARCHAR(180) NOT NULL,
  `to_location`   VARCHAR(180) NOT NULL,
  `rate_12m`      DECIMAL(10,2) NOT NULL,
  `notes`         VARCHAR(180) NULL,
  `active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_empty_carrier` (`carrier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `empty_rates` (`carrier`,`from_location`,`to_location`,`rate_12m`,`notes`) VALUES
('Grindrod','CT Terminals','GLO Belcon / Bellville / Milltrans / UCD / ICR',1950.00,NULL),
('Grindrod','CT Terminals','GLO Maitland / GLO Paarden Eiland / SACD / SATL / Salt River',875.00,NULL),
('Grindrod','CT Terminals','DCP / GLO Killarney',1700.00,NULL),
('UCD','CT Terminals','GLO Maitland / GLO Paarden Eiland / SACD / SATL / Salt River',1000.00,NULL),
('ONE','CT Terminals','ICR Sacks Circle / ICR Ampere',1900.00,NULL),
('ONE','CT Terminals','DCP',1750.00,NULL),
('ONE','CT Terminals','UCD',1650.00,NULL),
('ONE','ICR Sacks Circle','DCP / SACD',1650.00,NULL),
('ONE','ICR Sacks Circle','GLO Maitland',1400.00,NULL),
('ONE','Belcon','ICR Sacks Circle',850.00,NULL),
('CMA','CT Terminals','ICR Sacks Circle / DCP',1500.00,NULL),
('CMA','ICR Sacks Circle','GLO Maitland / SACD',1200.00,NULL),
('CMA','SSID','GLO Maitland',1200.00,NULL),
('CMA','GLO Belcon','ICR',800.00,NULL),
('Hapag-Lloyd','CT Terminals','ICR Sacks Circle',1750.00,NULL),
('Hapag-Lloyd','CT Terminals','DCP',1600.00,NULL),
('Hapag-Lloyd','CT Terminals','UCD',1500.00,NULL),
('Hapag-Lloyd','CT Terminals','GLO Maitland',1100.00,NULL),
('Hapag-Lloyd','ICR Sacks Circle','DCP',1500.00,NULL),
('Hapag-Lloyd','GLO Belcon','ICR Sacks Circle',800.00,NULL),
('PIL','ICR Sacks Circle','SACD',1650.00,NULL),
('PIL','CT Terminals','ICR Sacks Circle',1900.00,NULL),
('PIL','GLO Belcon','ICR Sacks Circle',850.00,NULL),
('Grindrod','SATL','Port',875.00,NULL),
('Grindrod','Paarden Eiland','Port',875.00,NULL),
('Deoxy','UCD','Port',1900.00,NULL),
('Coetzee','Port','Belcon',1900.00,NULL),
('Coetzee','Combi','Belcon',1900.00,NULL),
('Coetzee','MSC','Combi',900.00,NULL),
('Coetzee','Port','UCD (Salt River)',900.00,NULL),
('Coetzee','Port','UCD',1400.00,NULL),
('Coetzee','FPT','MSC',900.00,NULL),
('Coetzee','Killarney','CTCT',900.00,NULL),
('Coetzee','MSC','Port',900.00,NULL),
('DCP','DCP','Port',1500.00,NULL),
('DCP','Combi','DCP',1500.00,NULL),
('DCP','COSCO Port','DCP',1500.00,NULL),
('DCP','DCP','Paarden Eiland',1500.00,NULL),
('Bolster','Bolster Salt River','Port',1020.00,NULL),
('Bolster','UCD Salt River','Port',1020.00,NULL),
('Bolster','UCD Parow','Port',1530.00,NULL),
('Bolster','DCP','Port',1632.00,NULL),
('Bolster','Silver Solutions / Belcon / ICR','Port',1887.00,NULL);

-- ---------- Driver pay rate book (route + size -> driver pay) ----------
CREATE TABLE `driver_pay_rates` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `route`          VARCHAR(120) NOT NULL,
  `container_size` ENUM('6m','12m') NOT NULL DEFAULT '12m',
  `amount`         DECIMAL(10,2) NOT NULL DEFAULT 0,
  `notes`          VARCHAR(200) NULL,
  `active`         TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by`     INT          NULL,
  UNIQUE KEY `uq_driver_route_size` (`route`,`container_size`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `driver_pay_rates` (`route`,`container_size`,`amount`,`notes`) VALUES
('PORT TO MAITLAND','12m',200.00,'GRINDROD / EMPTY'),
('PORT TO MAITLAND','6m',100.00,'GRINDROD / EMPTY'),
('PORT TO KILLARNEY','12m',250.00,'GRINDROD / EMPTY'),
('PORT TO KILLARNEY','6m',125.00,'GRINDROD / EMPTY'),
('PORT TO BELCON','12m',250.00,'GRINDROD / EMPTY'),
('PORT TO BELCON','6m',125.00,'GRINDROD / EMPTY'),
('ICR TO PORT','12m',250.00,'GRINDROD / EMPTY'),
('ICR TO PORT','6m',125.00,'GRINDROD / EMPTY'),
('P/EIRLAND TO PORT','12m',200.00,'GRINDROD / EMPTY'),
('P/EIRLAND TO PORT','6m',100.00,'GRINDROD / EMPTY'),
('SATL TO PORT','12m',200.00,'GRINDROD / EMPTY'),
('SATL TO PORT','6m',200.00,'GRINDROD / EMPTY'),
('PORT TO BOLSTER','12m',200.00,'BOLSTER / EMPTY'),
('PORT TO BOLSTER','6m',100.00,'BOLSTER / EMPTY'),
('CFC PACKING','12m',450.00,'BOLSTER, DCP, MAXIMIZE / FULL'),
('CFC PACKING','6m',400.00,'BOLSTER, DCP, MAXIMIZE / FULL'),
('MONTAGUE GARDENS TO PORT','12m',450.00,'BOLSTER / FULL'),
('MONTAGUE GARDENS TO PORT','6m',400.00,'BOLSTER / FULL'),
('BOLSTER TO PORT','12m',250.00,'BOLSTER / XHAULS'),
('BOLSTER TO PORT','6m',250.00,'BOLSTER / XHAULS'),
('DCP TO PORT','12m',230.00,'DCP / EMPTY'),
('DCP TO PORT','6m',115.00,'DCP / EMPTY'),
('DCP TO PORT (XHAUL)','12m',400.00,'DCP / XHAULS'),
('DCP TO PORT (XHAUL)','6m',400.00,'DCP / XHAULS'),
('PORT TO KRAAIFONTEIN','12m',450.00,'MAXIMIZE / FULL'),
('PORT TO KRAAIFONTEIN','6m',400.00,'MAXIMIZE / FULL'),
('HILLBRAND TO PORT','12m',400.00,'LIMECOAL / FULL'),
('HILLBRAND TO PORT','6m',400.00,'LIMECOAL / FULL'),
('PORT TO DHL','12m',450.00,'FULL'),
('PORT TO DHL','6m',450.00,'FULL'),
('PORT TO PAROW','12m',400.00,'FULL'),
('PORT TO PAROW','6m',400.00,'FULL'),
('WORCESTER TO PORT','12m',750.00,'FULL'),
('WORCESTER TO PORT','6m',750.00,'FULL'),
('PAARL VALLEY-PORT','12m',700.00,'FULL'),
('PAARL VALLEY-PORT','6m',600.00,'FULL'),
('GRABOUW-PORT','12m',700.00,'FULL'),
('GRABOUW-PORT','6m',600.00,'FULL');

-- ------------------------------------------------------------
-- CRANE TRUCK jobs: quote -> invoice (call-out + 3-man crew + VAT)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `crane_jobs`;
CREATE TABLE `crane_jobs` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `status`           ENUM('quote','invoiced','paid','cancelled') NOT NULL DEFAULT 'quote',
  `quote_no`         VARCHAR(30) NULL,
  `invoice_no`       VARCHAR(30) NULL,
  `job_date`         DATE NOT NULL,
  `invoice_date`     DATE NULL,
  `due_date`         DATE NULL,
  `paid_at`          DATE NULL,
  `customer_name`    VARCHAR(160) NOT NULL,
  `customer_vat`     VARCHAR(40)  NULL,
  `customer_address` VARCHAR(300) NULL,
  `customer_ref`     VARCHAR(80)  NULL,
  `destination`      VARCHAR(160) NOT NULL,
  `round_trip_km`    DECIMAL(8,1) NOT NULL DEFAULT 0,
  `crew_hours`       DECIMAL(5,2) NOT NULL DEFAULT 0,
  `diesel_price`     DECIMAL(8,2) NOT NULL DEFAULT 0,
  `consumption`      DECIMAL(6,2) NOT NULL DEFAULT 0,
  `running_cost`     DECIMAL(8,2) NOT NULL DEFAULT 0,
  `diesel_cost`      DECIMAL(10,2) NOT NULL DEFAULT 0,
  `running_total`    DECIMAL(10,2) NOT NULL DEFAULT 0,
  `op_cost`          DECIMAL(10,2) NOT NULL DEFAULT 0,
  `callout_markup`   DECIMAL(6,2) NOT NULL DEFAULT 0,
  `callout_override` TINYINT(1) NOT NULL DEFAULT 0,
  `callout_charge`   DECIMAL(10,2) NOT NULL DEFAULT 0,
  `crew_rate`        DECIMAL(10,2) NOT NULL DEFAULT 0,
  `crew_charge`      DECIMAL(10,2) NOT NULL DEFAULT 0,
  `extra_desc`       VARCHAR(160) NULL,
  `extra_amount`     DECIMAL(10,2) NOT NULL DEFAULT 0,
  `subtotal`         DECIMAL(10,2) NOT NULL DEFAULT 0,
  `vat_pct`          DECIMAL(5,2)  NOT NULL DEFAULT 15,
  `vat_amount`       DECIMAL(10,2) NOT NULL DEFAULT 0,
  `total`            DECIMAL(10,2) NOT NULL DEFAULT 0,
  `notes`            VARCHAR(300) NULL,
  `created_by`       INT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_crane_status` (`status`),
  KEY `ix_crane_date` (`job_date`),
  UNIQUE KEY `uq_crane_quote` (`quote_no`),
  UNIQUE KEY `uq_crane_invoice` (`invoice_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  END OF SCHEMA.
-- ============================================================
