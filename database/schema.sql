-- Projekt: GewinneFinal2
-- Zweck: Datenbankschema für Gewinnspiele
-- Erstellungsdatum: 2023-11-29

CREATE DATABASE IF NOT EXISTS `gewinne_final2`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `gewinne_final2`;

CREATE TABLE IF NOT EXISTS `gewinnspiele` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `link_zur_webseite` VARCHAR(255) NOT NULL,
  `beschreibung` TEXT NULL,
  `status` ENUM('geplant', 'aktiv', 'abgelaufen') NOT NULL DEFAULT 'geplant',
  `endet_am` DATETIME NULL
) ENGINE=InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;
