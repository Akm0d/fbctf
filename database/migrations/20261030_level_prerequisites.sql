SET @has_requires_level := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'levels'
    AND COLUMN_NAME = 'requires_level_id'
);
SET @sql := IF(
  @has_requires_level = 0,
  'ALTER TABLE levels ADD COLUMN requires_level_id INT NULL AFTER penalty, ADD INDEX requires_level_id (requires_level_id), ADD CONSTRAINT levels_requires_level_fk FOREIGN KEY (requires_level_id) REFERENCES levels(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_completion_message := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'levels'
    AND COLUMN_NAME = 'completion_message'
);
SET @sql := IF(
  @has_completion_message = 0,
  'ALTER TABLE levels ADD COLUMN completion_message TEXT NULL AFTER requires_level_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
