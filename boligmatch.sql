-- ================================================================
--  boligmatch.sql — Complete Database Schema + Test Data
--  Import into phpMyAdmin: create DB 'boligmatch', then import this
-- ================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `users` (
  `id`                   INT          NOT NULL AUTO_INCREMENT,
  `first_name`           VARCHAR(80)  NOT NULL,
  `last_name`            VARCHAR(80)  NOT NULL,
  `email`                VARCHAR(255) NOT NULL,
  `phone`                VARCHAR(30)  DEFAULT NULL,
  `password_hash`        VARCHAR(255) NOT NULL,
  `role`                 ENUM('student','owner','admin','banned') NOT NULL DEFAULT 'student',
  `university`           VARCHAR(200) DEFAULT NULL,
  `address_city`         VARCHAR(200) DEFAULT NULL,
  `email_verified`       TINYINT(1)   NOT NULL DEFAULT 0,
  `verify_token`         VARCHAR(64)  DEFAULT NULL,
  `reset_token`          VARCHAR(64)  DEFAULT NULL,
  `reset_expires`        DATETIME     DEFAULT NULL,
  `ownership_document`   VARCHAR(255) DEFAULT NULL,
  `ownership_verified`   TINYINT(1)   NOT NULL DEFAULT 0,
  `ownership_rejected`   TINYINT(1)   NOT NULL DEFAULT 0,
  `ownership_uploaded_at` DATETIME    DEFAULT NULL,
  `last_login`           DATETIME     DEFAULT NULL,
  `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `listings` (
  `id`               INT            NOT NULL AUTO_INCREMENT,
  `owner_id`         INT            NOT NULL,
  `title`            VARCHAR(200)   NOT NULL,
  `description`      TEXT           NOT NULL,
  `city`             VARCHAR(50)    NOT NULL,
  `area`             VARCHAR(100)   NOT NULL,
  `address_full`     VARCHAR(300)   DEFAULT NULL,
  `room_type`        ENUM('single','double') NOT NULL DEFAULT 'single',
  `room_size_m2`     INT            DEFAULT NULL,
  `total_flatmates`  INT            NOT NULL DEFAULT 1,
  `bathroom_type`    ENUM('private','shared') NOT NULL DEFAULT 'shared',
  `rent_monthly`     DECIMAL(10,2)  NOT NULL,
  `deposit_months`   INT            NOT NULL DEFAULT 1,
  `bills_included`   TINYINT(1)     NOT NULL DEFAULT 0,
  `furnished`        TINYINT(1)     NOT NULL DEFAULT 0,
  `wifi`             TINYINT(1)     NOT NULL DEFAULT 0,
  `washing_machine`  TINYINT(1)     NOT NULL DEFAULT 0,
  `parking`          TINYINT(1)     NOT NULL DEFAULT 0,
  `pets_allowed`     TINYINT(1)     NOT NULL DEFAULT 0,
  `balcony`          TINYINT(1)     NOT NULL DEFAULT 0,
  `photos`           TEXT           DEFAULT NULL,
  `available_from`   DATE           DEFAULT NULL,
  `min_lease_months` INT            NOT NULL DEFAULT 3,
  `status`           ENUM('active','pending','expired') NOT NULL DEFAULT 'active',
  `view_count`       INT            NOT NULL DEFAULT 0,
  `created_at`       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_owner` (`owner_id`),
  KEY `idx_status` (`status`),
  KEY `idx_city` (`city`),
  CONSTRAINT `fk_listings_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `messages` (
  `id`           INT        NOT NULL AUTO_INCREMENT,
  `sender_id`    INT        NOT NULL,
  `receiver_id`  INT        NOT NULL,
  `listing_id`   INT        NOT NULL,
  `body`         TEXT       NOT NULL,
  `is_read`      TINYINT(1) NOT NULL DEFAULT 0,
  `flagged`      TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`   DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sender` (`sender_id`),
  KEY `idx_receiver` (`receiver_id`),
  KEY `idx_listing` (`listing_id`),
  CONSTRAINT `fk_msg_sender`   FOREIGN KEY (`sender_id`)   REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_msg_receiver` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_msg_listing`  FOREIGN KEY (`listing_id`)  REFERENCES `listings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `saved_listings` (
  `id`          INT      NOT NULL AUTO_INCREMENT,
  `student_id`  INT      NOT NULL,
  `listing_id`  INT      NOT NULL,
  `saved_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_save` (`student_id`, `listing_id`),
  CONSTRAINT `fk_saved_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_saved_listing` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `reports` (
  `id`          INT          NOT NULL AUTO_INCREMENT,
  `reporter_id` INT          NOT NULL,
  `listing_id`  INT          NOT NULL,
  `reason`      VARCHAR(50)  NOT NULL,
  `details`     TEXT         DEFAULT NULL,
  `status`      ENUM('open','reviewed','closed') NOT NULL DEFAULT 'open',
  `resolved_at` DATETIME     DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_rpt_reporter` FOREIGN KEY (`reporter_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rpt_listing`  FOREIGN KEY (`listing_id`)  REFERENCES `listings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Views ─────────────────────────────────────────────────────
CREATE OR REPLACE VIEW `v_active_listings` AS
SELECT l.*, u.first_name AS owner_first, u.last_name AS owner_last, u.email_verified AS owner_verified
FROM listings l JOIN users u ON u.id = l.owner_id WHERE l.status = 'active';

CREATE OR REPLACE VIEW `v_student_users` AS SELECT * FROM users WHERE role = 'student';
CREATE OR REPLACE VIEW `v_owner_users`   AS SELECT * FROM users WHERE role = 'owner';

-- ================================================================
--  SAMPLE DATA (all fictional — password for all: Test1234!)
-- ================================================================
SET @pw = '$2y$12$LJ3m4ys3Gz8y3yf4t6VKnOWzBbvXGh0CzQ7qYJTZDGJTZxcJKrW.e';

INSERT INTO `users` (`first_name`,`last_name`,`email`,`password_hash`,`role`,`email_verified`,`created_at`) VALUES
('Admin','BoligMatch','admin@boligmatch.local',@pw,'admin',1,'2025-01-01 10:00:00');

INSERT INTO `users` (`first_name`,`last_name`,`email`,`phone`,`password_hash`,`role`,`address_city`,`email_verified`,`created_at`) VALUES
('Lars','Nielsen','lars@example.dk','+45 22 33 44 55',@pw,'owner','Nørrebro, Copenhagen',1,'2025-02-10 09:00:00'),
('Mette','Andersen','mette@example.dk','+45 33 44 55 66',@pw,'owner','Vesterbro, Copenhagen',1,'2025-02-15 14:30:00'),
('Henrik','Sørensen','henrik@example.dk','+45 44 55 66 77',@pw,'owner','Aarhus C',1,'2025-03-01 11:00:00'),
('Sofie','Mortensen','sofie@example.dk','+45 55 66 77 88',@pw,'owner','Aalborg Øst',0,'2025-03-20 16:00:00');

INSERT INTO `users` (`first_name`,`last_name`,`email`,`phone`,`password_hash`,`role`,`university`,`email_verified`,`created_at`) VALUES
('Amara','Okafor','amara@student.dk','+45 11 22 33 44',@pw,'student','University of Copenhagen (KU)',1,'2025-02-20 08:00:00'),
('Carlos','Rivera','carlos@student.dk','+45 22 33 44 55',@pw,'student','Copenhagen Business School (CBS)',1,'2025-03-05 12:00:00'),
('Priya','Sharma','priya@student.dk',NULL,@pw,'student','Technical University of Denmark (DTU)',1,'2025-03-10 15:00:00'),
('Yuki','Tanaka','yuki@student.dk','+45 33 44 55 66',@pw,'student','Niels Brock Copenhagen Business College',0,'2025-04-01 10:00:00');

INSERT INTO `listings` (`owner_id`,`title`,`description`,`city`,`area`,`room_type`,`room_size_m2`,`total_flatmates`,`bathroom_type`,`rent_monthly`,`deposit_months`,`bills_included`,`furnished`,`wifi`,`washing_machine`,`balcony`,`pets_allowed`,`available_from`,`min_lease_months`,`status`,`view_count`,`created_at`) VALUES
(2,'Bright Double Room in Shared 3-Bed Flat','Spacious 18m² room with large windows facing south. 5-minute walk to Nørrebro S-train station. Shared kitchen recently renovated. Quiet neighbourhood, perfect for studying.','Copenhagen','Nørrebro','double',18,3,'shared',4800.00,1,0,1,1,1,0,0,'2025-04-01',6,'active',24,'2025-03-01 10:00:00'),
(2,'Cozy Single Room near Assistens Cemetery','Small but well-designed room in a charming building. 10 min walk to KU North Campus. Furnished with desk and bed. Bills included in rent.','Copenhagen','Nørrebro','single',12,2,'shared',3900.00,1,1,1,1,0,0,0,'2025-05-01',3,'active',18,'2025-03-05 14:00:00'),
(3,'Furnished Room in Vesterbro with Balcony','Lovely furnished room with private balcony overlooking a green courtyard. Close to Enghave Plads metro. Washing machine available.','Copenhagen','Vesterbro','double',16,2,'shared',5500.00,2,0,1,1,1,1,0,'2025-04-15',6,'active',31,'2025-03-10 09:30:00'),
(4,'Affordable Room in Aarhus City Centre','Budget-friendly single room in the heart of Aarhus. Walking distance to Aarhus University. Shared bathroom and kitchen.','Aarhus','Aarhus C','single',10,4,'shared',3200.00,1,0,0,1,0,0,0,'2025-04-01',3,'active',12,'2025-03-15 11:00:00'),
(4,'Modern Double Room near Aarhus Harbour','Newly renovated room in a modern building near the harbour. Private bathroom, furnished. Bills included. Pet-friendly.','Aarhus','Aarhus Ø','double',20,2,'private',6200.00,2,1,1,1,1,0,1,'2025-05-01',6,'active',8,'2025-03-20 16:30:00'),
(5,'Student Room in Aalborg East','Simple student room near Aalborg University campus. Good bus connections. Shared facilities. Very affordable.','Aalborg','Aalborg Øst','single',11,4,'shared',2800.00,1,0,0,1,1,0,0,'2025-04-15',3,'active',5,'2025-04-01 08:00:00');

INSERT INTO `messages` (`sender_id`,`receiver_id`,`listing_id`,`body`,`is_read`,`flagged`,`created_at`) VALUES
(6,2,1,'Hi Lars! I am interested in your room in Nørrebro. Is it still available?',1,0,'2025-03-02 10:30:00'),
(2,6,1,'Hi Amara! Yes it is. Would Thursday at 15:00 work for a viewing?',1,0,'2025-03-02 14:15:00'),
(6,2,1,'Thursday at 15:00 works perfectly! Thank you!',1,0,'2025-03-02 16:00:00'),
(7,3,3,'Hello Mette, I saw your listing in Vesterbro. Can I visit this weekend?',1,0,'2025-03-11 09:00:00'),
(3,7,3,'Hi Carlos! Sure. Are you free Saturday morning?',0,0,'2025-03-11 12:30:00'),
(8,4,4,'Hi Henrik, is the room in Aarhus still available?',0,0,'2025-03-16 10:00:00');

INSERT INTO `saved_listings` (`student_id`,`listing_id`,`saved_at`) VALUES
(6,1,'2025-03-01 11:00:00'),(6,3,'2025-03-10 10:00:00'),
(7,2,'2025-03-06 09:00:00'),(7,3,'2025-03-10 14:00:00'),
(8,4,'2025-03-16 09:30:00'),(8,5,'2025-03-20 17:00:00');

INSERT INTO `reports` (`reporter_id`,`listing_id`,`reason`,`details`,`status`,`created_at`) VALUES
(8,6,'suspicious_owner','Owner has not verified their email and rent seems very low.','open','2025-04-02 11:00:00');
