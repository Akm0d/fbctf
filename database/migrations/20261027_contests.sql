CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `version` varchar(100) NOT NULL,
  `applied_ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `contests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `description` text NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `submission_type` varchar(10) NOT NULL,
  `vote_points` int(11) NOT NULL DEFAULT 0,
  `winner_points` int(11) NOT NULL DEFAULT 0,
  `submission_open_ts` datetime DEFAULT NULL,
  `submission_close_ts` datetime DEFAULT NULL,
  `voting_open_ts` datetime DEFAULT NULL,
  `voting_close_ts` datetime DEFAULT NULL,
  `visible` tinyint(1) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 0,
  `finalized` tinyint(1) NOT NULL DEFAULT 0,
  `winning_entry_id` int(11) DEFAULT NULL,
  `created_ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `contest_category` (`category_id`),
  KEY `contest_visibility` (`visible`,`active`),
  CONSTRAINT `contests_category_fk` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `contest_entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contest_id` int(11) NOT NULL,
  `team_id` int(11) NOT NULL,
  `text_content` text DEFAULT NULL,
  `image_filename` varchar(255) DEFAULT NULL,
  `created_ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contest_team_entry` (`contest_id`,`team_id`),
  KEY `entry_team` (`team_id`),
  CONSTRAINT `contest_entries_contest_fk` FOREIGN KEY (`contest_id`) REFERENCES `contests` (`id`),
  CONSTRAINT `contest_entries_team_fk` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `contest_points_ledger` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contest_id` int(11) NOT NULL,
  `team_id` int(11) NOT NULL,
  `entry_id` int(11) DEFAULT NULL,
  `reward_type` varchar(10) NOT NULL,
  `points` int(11) NOT NULL,
  `idempotency_key` varchar(100) NOT NULL,
  `created_ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contest_reward_once` (`idempotency_key`),
  KEY `ledger_contest` (`contest_id`),
  KEY `ledger_team` (`team_id`),
  CONSTRAINT `contest_ledger_contest_fk` FOREIGN KEY (`contest_id`) REFERENCES `contests` (`id`),
  CONSTRAINT `contest_ledger_team_fk` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`),
  CONSTRAINT `contest_ledger_entry_fk` FOREIGN KEY (`entry_id`) REFERENCES `contest_entries` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `schema_migrations` (`version`)
VALUES ('20261027_contests');
