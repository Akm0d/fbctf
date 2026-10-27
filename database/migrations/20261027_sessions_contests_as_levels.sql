ALTER TABLE `contests`
  ADD COLUMN IF NOT EXISTS `level_id` int(11) DEFAULT NULL AFTER `id`;

CREATE TABLE IF NOT EXISTS `contest_session_votes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contest_id` int(11) NOT NULL,
  `voter_session_key` char(64) NOT NULL,
  `voter_team_id` int(11) NOT NULL,
  `entry_id` int(11) NOT NULL,
  `created_ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contest_session_vote_once` (`contest_id`,`voter_session_key`),
  KEY `session_vote_team` (`contest_id`,`voter_team_id`),
  KEY `session_vote_entry` (`entry_id`),
  CONSTRAINT `contest_session_votes_contest_fk` FOREIGN KEY (`contest_id`) REFERENCES `contests` (`id`),
  CONSTRAINT `contest_session_votes_team_fk` FOREIGN KEY (`voter_team_id`) REFERENCES `teams` (`id`),
  CONSTRAINT `contest_session_votes_entry_fk` FOREIGN KEY (`entry_id`) REFERENCES `contest_entries` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @contest_level_unique_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'contests'
    AND index_name = 'contest_level_unique'
);
SET @contest_level_unique_sql = IF(
  @contest_level_unique_exists = 0,
  'ALTER TABLE `contests` ADD UNIQUE KEY `contest_level_unique` (`level_id`)',
  'SELECT 1'
);
PREPARE contest_level_unique_stmt FROM @contest_level_unique_sql;
EXECUTE contest_level_unique_stmt;
DEALLOCATE PREPARE contest_level_unique_stmt;

SET @contest_level_fk_exists = (
  SELECT COUNT(*) FROM information_schema.table_constraints
  WHERE constraint_schema = DATABASE()
    AND table_name = 'contests'
    AND constraint_name = 'contests_level_fk'
);
SET @contest_level_fk_sql = IF(
  @contest_level_fk_exists = 0,
  'ALTER TABLE `contests` ADD CONSTRAINT `contests_level_fk` FOREIGN KEY (`level_id`) REFERENCES `levels` (`id`)',
  'SELECT 1'
);
PREPARE contest_level_fk_stmt FROM @contest_level_fk_sql;
EXECUTE contest_level_fk_stmt;
DEALLOCATE PREPARE contest_level_fk_stmt;

INSERT IGNORE INTO `schema_migrations` (`version`)
VALUES ('20261027_contests_as_levels');
