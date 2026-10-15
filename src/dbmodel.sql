
-- ------
-- BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
-- MinimumJourney implementation : © <Your name here> <Your email address here>
-- 
-- This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
-- See http://en.boardgamearena.com/#!doc/Studio for more information.
-- -----

-- This is the file where you are describing the database schema of your game
-- Basically, you just have to export from PhpMyAdmin your table structure and copy/paste
-- this export here.
-- Note that the database itself and the standard tables ("global", "stats", "gamelog" and "player") are
-- already created and must not be created here

-- Note: The database schema is created from this file when the game starts. If you modify this file,
--       you have to restart a game to see your changes in database.

-- MINIMUM JOURNEY のテーブル定義（docs/specs/007-data-model.md §3.1、§4.1、§4.4）。
-- card テーブルは ItemManager / Deck ではなく自前の CardRepository が読み書きする（007 の確認済み（I-02）を参照）。

CREATE TABLE IF NOT EXISTS `card` (
  `card_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `card_location` VARCHAR(16) NOT NULL,
  `card_location_arg` INT NOT NULL DEFAULT 0,
  `card_order` INT NOT NULL DEFAULT 0,
  `card_no` SMALLINT UNSIGNED NOT NULL,
  `card_art` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `card_sideways` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`card_id`),
  KEY `idx_card_loc` (`card_location`, `card_location_arg`, `card_order`),
  KEY `idx_card_no` (`card_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1;

CREATE TABLE IF NOT EXISTS `resolve_entry` (
  `entry_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entry_seq` SMALLINT NOT NULL,
  `entry_player` INT UNSIGNED NOT NULL,
  `entry_card_id` INT UNSIGNED NULL DEFAULT NULL,
  `entry_card_no` SMALLINT UNSIGNED NOT NULL,
  `entry_origin` VARCHAR(8) NOT NULL,
  `entry_host` INT UNSIGNED NULL DEFAULT NULL,
  `entry_level` SMALLINT NULL DEFAULT NULL,
  `entry_eff_level` SMALLINT NULL DEFAULT NULL,
  `entry_status` VARCHAR(8) NOT NULL DEFAULT 'pending',
  `entry_copy_no` SMALLINT UNSIGNED NULL DEFAULT NULL,
  `entry_decision` TINYINT NULL DEFAULT NULL,
  PRIMARY KEY (`entry_id`),
  KEY `idx_entry_status` (`entry_status`, `entry_eff_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1;

ALTER TABLE `player` ADD `prep_finished` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `player` ADD `prep_choice` VARCHAR(4) NULL DEFAULT NULL;
ALTER TABLE `player` ADD `is_halved` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `player` ADD `explore_output` INT NULL DEFAULT NULL;
