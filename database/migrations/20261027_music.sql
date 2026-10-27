CREATE TABLE IF NOT EXISTS `music_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `team_id` int(11) NOT NULL,
  `youtube_id` char(11) NOT NULL,
  `display_title` varchar(255) NOT NULL,
  `request_type` varchar(10) NOT NULL DEFAULT 'regular',
  `status` varchar(12) NOT NULL DEFAULT 'pending',
  `position` int(11) NOT NULL DEFAULT 0,
  `created_ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `music_request_team` (`team_id`),
  KEY `music_request_status_position` (`status`,`position`),
  CONSTRAINT `music_request_team_fk` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `music_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) DEFAULT NULL,
  `youtube_id` char(11) NOT NULL,
  `display_title` varchar(255) NOT NULL,
  `request_team_id` int(11) DEFAULT NULL,
  `is_karaoke` tinyint(1) NOT NULL DEFAULT 0,
  `source_type` varchar(10) NOT NULL DEFAULT 'admin',
  `status` varchar(10) NOT NULL DEFAULT 'queued',
  `position` int(11) NOT NULL DEFAULT 0,
  `started_ts` datetime DEFAULT NULL,
  `finished_ts` datetime DEFAULT NULL,
  `created_ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `music_queue_status_position` (`status`,`position`),
  KEY `music_queue_team` (`request_team_id`),
  KEY `music_queue_request` (`request_id`),
  CONSTRAINT `music_queue_request_fk` FOREIGN KEY (`request_id`) REFERENCES `music_requests` (`id`),
  CONSTRAINT `music_queue_team_fk` FOREIGN KEY (`request_team_id`) REFERENCES `teams` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `music_playback_state` (
  `id` tinyint(1) NOT NULL,
  `current_queue_id` int(11) DEFAULT NULL,
  `desired_state` varchar(10) NOT NULL DEFAULT 'paused',
  `command_version` bigint(20) NOT NULL DEFAULT 0,
  `active_tv_key_hash` char(64) DEFAULT NULL,
  `active_tv_lease_until` datetime DEFAULT NULL,
  `updated_ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `music_playback_current` (`current_queue_id`),
  CONSTRAINT `music_playback_current_fk` FOREIGN KEY (`current_queue_id`) REFERENCES `music_queue` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `music_playback_state` (`id`) VALUES (1);

CREATE TABLE IF NOT EXISTS `karaoke_stage_state` (
  `id` tinyint(1) NOT NULL,
  `activity_level_id` int(11) DEFAULT NULL,
  `stage_level_id` int(11) DEFAULT NULL,
  `owner_team_id` int(11) DEFAULT NULL,
  `performance_points` int(11) NOT NULL DEFAULT 50,
  `passive_points_per_minute` int(11) NOT NULL DEFAULT 5,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `ownership_version` int(11) NOT NULL DEFAULT 0,
  `last_accrual_ts` datetime DEFAULT NULL,
  `captured_ts` datetime DEFAULT NULL,
  `updated_ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `karaoke_stage_owner` (`owner_team_id`),
  CONSTRAINT `karaoke_stage_activity_level_fk` FOREIGN KEY (`activity_level_id`) REFERENCES `levels` (`id`),
  CONSTRAINT `karaoke_stage_level_fk` FOREIGN KEY (`stage_level_id`) REFERENCES `levels` (`id`),
  CONSTRAINT `karaoke_stage_owner_fk` FOREIGN KEY (`owner_team_id`) REFERENCES `teams` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `karaoke_stage_state` (`id`) VALUES (1);

CREATE TABLE IF NOT EXISTS `karaoke_performances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `queue_id` int(11) NOT NULL,
  `request_id` int(11) DEFAULT NULL,
  `team_id` int(11) NOT NULL,
  `youtube_id` char(11) NOT NULL,
  `display_title` varchar(255) NOT NULL,
  `admin_team_id` int(11) NOT NULL,
  `points` int(11) NOT NULL,
  `completed_ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `karaoke_performance_queue_once` (`queue_id`),
  KEY `karaoke_performance_team` (`team_id`),
  CONSTRAINT `karaoke_performance_queue_fk` FOREIGN KEY (`queue_id`) REFERENCES `music_queue` (`id`),
  CONSTRAINT `karaoke_performance_request_fk` FOREIGN KEY (`request_id`) REFERENCES `music_requests` (`id`),
  CONSTRAINT `karaoke_performance_team_fk` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`),
  CONSTRAINT `karaoke_performance_admin_fk` FOREIGN KEY (`admin_team_id`) REFERENCES `teams` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `music_points_ledger` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `team_id` int(11) NOT NULL,
  `performance_id` int(11) DEFAULT NULL,
  `reward_type` varchar(12) NOT NULL,
  `points` int(11) NOT NULL,
  `idempotency_key` varchar(120) NOT NULL,
  `created_ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `music_reward_once` (`idempotency_key`),
  KEY `music_ledger_team` (`team_id`),
  CONSTRAINT `music_ledger_team_fk` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`),
  CONSTRAINT `music_ledger_performance_fk` FOREIGN KEY (`performance_id`) REFERENCES `karaoke_performances` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('20261027_music');
