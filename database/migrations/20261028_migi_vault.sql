-- Initialize existing Migi-category Bases as recapturable manual bases.
-- The application owns all subsequent flag rotations.
UPDATE `levels` l
JOIN `categories` c ON c.id = l.category_id
SET l.flag = CONCAT(
  'MIGI-',
  UPPER(HEX(RANDOM_BYTES(2))), '-',
  UPPER(HEX(RANDOM_BYTES(2))), '-',
  UPPER(HEX(RANDOM_BYTES(2))), '-',
  UPPER(HEX(RANDOM_BYTES(2)))
)
WHERE l.type = 'base'
  AND c.category = 'Migi'
  AND l.flag = '';

INSERT IGNORE INTO `manual_base_state` (`level_id`)
SELECT l.id
FROM `levels` l
JOIN `categories` c ON c.id = l.category_id
WHERE l.type = 'base'
  AND c.category = 'Migi';

INSERT IGNORE INTO `schema_migrations` (`version`)
VALUES ('20261028_migi_vault');
