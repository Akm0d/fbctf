CREATE TABLE IF NOT EXISTS `manual_base_state` (
  `level_id` int(11) NOT NULL,
  `owner_team_id` int(11) DEFAULT NULL,
  `ownership_version` int(11) NOT NULL DEFAULT 0,
  `accrual_seconds` int(11) NOT NULL DEFAULT 60,
  `last_accrual_ts` datetime DEFAULT NULL,
  `captured_ts` datetime DEFAULT NULL,
  `updated_by_admin_id` int(11) DEFAULT NULL,
  `updated_ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`level_id`),
  KEY `manual_base_owner` (`owner_team_id`),
  CONSTRAINT `manual_base_level_fk` FOREIGN KEY (`level_id`) REFERENCES `levels` (`id`) ON DELETE CASCADE,
  CONSTRAINT `manual_base_owner_fk` FOREIGN KEY (`owner_team_id`) REFERENCES `teams` (`id`),
  CONSTRAINT `manual_base_admin_fk` FOREIGN KEY (`updated_by_admin_id`) REFERENCES `teams` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `manual_base_points_ledger` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `level_id` int(11) NOT NULL,
  `team_id` int(11) NOT NULL,
  `ownership_version` int(11) NOT NULL,
  `reward_type` varchar(12) NOT NULL,
  `points` int(11) NOT NULL,
  `idempotency_key` varchar(160) NOT NULL,
  `created_ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `manual_base_reward_once` (`idempotency_key`),
  KEY `manual_base_ledger_level` (`level_id`),
  KEY `manual_base_ledger_team` (`team_id`),
  CONSTRAINT `manual_base_ledger_level_fk` FOREIGN KEY (`level_id`) REFERENCES `levels` (`id`) ON DELETE CASCADE,
  CONSTRAINT `manual_base_ledger_team_fk` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Convert the party's host-awarded Uno flag into a manually controlled base.
UPDATE `levels` l
JOIN `categories` c ON c.id = l.category_id
SET l.type = 'base',
    l.title = 'The Last Card Laughs',
    l.description = 'The team that most recently wins a complete game of Uno controls this base. Tyler selects the current winner in Admin.',
    l.points = 5,
    l.bonus = 50,
    l.bonus_dec = 0,
    l.bonus_fix = 50,
    l.flag = '',
    l.hint = 'Challenge another team to Uno, then report the result to Tyler.',
    l.penalty = 0
WHERE l.id = 89
  AND c.category = 'Social Quests'
  AND l.title IN ('Uno Champion', 'The Last Card Laughs');

INSERT IGNORE INTO `manual_base_state` (`level_id`)
SELECT l.id FROM `levels` l
WHERE l.id = 89 AND l.type = 'base' AND l.title = 'The Last Card Laughs';

-- Titles are deliberately oblique: the question should carry the facts, not
-- the title. Restrict updates to the known party board so fresh installations
-- with unrelated IDs are never overwritten.
CREATE TEMPORARY TABLE `party_copy_polish` (
  `id` int(11) NOT NULL,
  `old_title` varchar(255) NOT NULL,
  `new_title` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
);

INSERT INTO `party_copy_polish` (`id`, `old_title`, `new_title`) VALUES
  (20, 'All In, All the Time', 'The Dive Button'),
  (21, 'The Backup Battle Cry', 'The White-Flag Allergy'),
  (22, 'The Original Handle', 'Five Letters, Zero Chill'),
  (23, 'When the Name Is Taken', 'Imperial Expansion Pack'),
  (24, 'A Name from Jerusalem', 'Cafeteria Lore from Abroad'),
  (25, 'The Hacker Backronym', 'Kernel Panic at the Disco'),
  (26, 'Ember Writes the Rules', 'The Manuscript in Orbit'),
  (27, 'Ryan''s Favorite Hero', 'Web-Slinger Hotline'),
  (28, 'The Gold Telepath', 'A Very Shiny Thought'),
  (29, 'The Cat on Duty', 'Orange Management'),
  (30, 'The Other Future Man Cat', 'Predator Payroll'),
  (31, 'The Hypothetical Third Cat', 'The Cat Who Never Was'),
  (32, 'Mister the Guest Cat', 'Formalwear Required'),
  (33, 'Future Man Naming Scheme', 'Chronological Feline Nonsense'),
  (34, 'Home Base', 'Exit 288-ish'),
  (35, 'Imported Loot', 'Contraband Snack Run'),
  (36, 'Mastered at WGU', 'Owl-Free Graduate School'),
  (37, 'Bachelor''s from the Y', 'Cougar Undergraduate Detour'),
  (38, 'The Shared Surname', 'The Name-Sharing Maneuver'),
  (39, 'Levys Legacy', 'The Anti-Fascist Branch'),
  (40, 'The First Johnson in America', 'The Swedish Root'),
  (41, 'The Ancestor''s Utah City', 'Pioneer City Limits'),
  (42, 'Jungle Requiem', 'Press R and Touch Grass'),
  (43, 'A Painter of Possibilities', 'Too Many Colors'),
  (44, 'Megans Main', 'Encore in Mid Lane'),
  (45, 'Embers Loose Cannon', 'Blue Braids at Dinner'),
  (46, 'Everyone Has Seen It', 'Two Cities, One Bridge'),
  (47, 'Modern Pink Wards', 'Vision Inflation'),
  (48, 'Skaarl in the Basement', 'Basement Cavalry'),
  (49, 'Who Rides Skaarl', 'The Angriest Commute'),
  (50, 'The Personal AI', 'Right Hand, Wrong Server'),
  (51, 'Migi''s Namesake', 'An Anime with Boundary Issues'),
  (52, 'Uploaded Intelligence', 'Cloud Gods and Bad Decisions'),
  (53, 'By the Power of Grayskull', 'Baby Has the Power'),
  (54, 'The Anticipated Bug Kingdom', 'Needle, Thread, and Waiting'),
  (55, 'The Chill Little Cult', 'Tiny Prophet, Huge Mortgage'),
  (56, 'Irken Invader', 'The Tallest Lawn Ornament'),
  (57, 'The League Plush', 'Snack-Fueled Orb'),
  (58, 'A Terrible Mask', 'Moon Insurance Claim'),
  (59, 'Alien''s Survivor', 'Airlock Etiquette'),
  (60, 'Prince''s Alien Alphabet', 'Bedtime on the Nostromo'),
  (61, 'Tonight in Sunnydale', 'Tuesday Night in a Hellmouth'),
  (62, 'The Old-School Vampire', 'Victorian Neck Problems'),
  (63, 'Vampires of Tamriel', 'Cold Weather Bloodsuckers'),
  (64, 'Asymmetric Horror Nights', 'Four Friends and a Bad Evening'),
  (65, 'Art from Tyria', 'Coffee-Table Loot Drop'),
  (66, 'Princesses of Power', 'The Other Sword-Raiser'),
  (67, 'Red Means Health', 'Potion Bowl Number One'),
  (68, 'Blue Means Mana', 'Potion Bowl Number Two'),
  (69, 'Megan Behind the Seams', 'Needles under Stage Lights'),
  (70, 'The Current Production', 'Tonight''s Call Sheet'),
  (71, 'A Week in High Heels', 'Fashion Crimes Department'),
  (72, 'The Striped Hat Role', 'Seussical Personnel File'),
  (73, 'The House Electrician', 'Do Not Lick the Outlet'),
  (74, 'Natasia''s Rift', 'One Ring, Five Stack'),
  (75, 'The Pandemic Meta', 'The Server Time Forgot'),
  (76, 'Cousins on the Dance Floor', 'The Cousin Crime Scene'),
  (77, 'The Cousins'' Voice Channel', 'A Proudly Questionable Queue'),
  (78, 'Nick''s Force', 'Nick Assembled a Unit'),
  (79, 'Rachel Gets Sweaty', 'Perspiration by Typo'),
  (80, 'Into the Sweaty Pit', 'Voice Chat Humidity'),
  (81, 'Boomer Spelling Bee', 'Compliment Gone Damp'),
  (82, 'DJ''s Fancy Neighborhood', 'Luxury Housing, Allegedly'),
  (83, 'Top of the Slums', 'Reverse Aristocracy I'),
  (84, 'Bottom of the Slums', 'Reverse Aristocracy II'),
  (85, 'Ryan''s Secret Mission', 'Web Fluid Diplomacy'),
  (86, 'Interview the Author', 'The Unpublished Oracle'),
  (87, 'Theater Stitchwork', 'Needlework Intelligence'),
  (88, 'Fully Charged', 'Breaker-Panel Diplomacy'),
  (90, 'Keys to the Crypt', 'Thirty Seconds of Courage'),
  (91, 'Prompt the Machine', 'Adversarial Tea Party'),
  (92, 'Three Wheels by the Door', 'Helmet Convention'),
  (93, 'A Terrible Fate', 'Moon-Faced Witness'),
  (94, 'Fluff and Fury', 'Snack-Seeking Puffball'),
  (95, 'The Alchemist''s Shelf', 'Solve et Coagula'),
  (96, 'Tyria in Print', 'Coffee-Table Cartography'),
  (97, 'The House of Leaves', 'The Five-Minute Hallway'),
  (98, 'The Freeman Codex', 'Wake Up, Theorist'),
  (99, 'Sunnydale Projection', 'Ceiling-Mounted Hellmouth'),
  (100, 'Automated Drapery', 'The House Blinks First'),
  (101, 'The Sonic Shield', 'Outlet Choir'),
  (102, 'Fire in the Backyard', 'Propane Oracle'),
  (103, 'The Dank Storehouse', 'Fast Offerings, Slow Internet'),
  (104, 'A Complicated Pioneer', 'The Family Tree Has Side Quests'),
  (105, 'From the Y to Competency', 'Degrees of Separation'),
  (106, 'The Reversed Hierarchy', 'Aristocracy in Reverse'),
  (107, 'Sparkly Vampire Verdict', 'Glitter Is Not a Survival Trait');

UPDATE `levels` l
JOIN `party_copy_polish` p ON p.id = l.id AND l.title IN (p.old_title, p.new_title)
SET l.title = p.new_title;

-- Make every prompt identify Tyler rather than referring vaguely to "the host"
-- and clarify the handful of answers that previously demanded an unstated form.
UPDATE `levels` l JOIN `party_copy_polish` p ON p.id = l.id AND l.title = p.new_title SET l.description = REPLACE(l.description, 'the host''s', 'Tyler''s');
UPDATE `levels` l JOIN `party_copy_polish` p ON p.id = l.id AND l.title = p.new_title SET l.description = REPLACE(l.description, 'the hosts', 'Ember and Tyler');
UPDATE `levels` l JOIN `party_copy_polish` p ON p.id = l.id AND l.title = p.new_title SET l.description = REPLACE(l.description, 'the host', 'Tyler');
UPDATE `levels` l JOIN `party_copy_polish` p ON p.id = l.id AND l.title = p.new_title SET l.description = REPLACE(l.description, 'Tylers', 'Ember and Tyler');
UPDATE `levels` l JOIN `party_copy_polish` p ON p.id = l.id AND l.title = p.new_title SET l.hint = REPLACE(l.hint, 'the host', 'Tyler');

UPDATE `levels` SET
  description = 'What exact League of Legends catchphrase does Tyler say before committing to a risky play?',
  hint = 'Ask someone who has played League with Tyler; it is not "Never Surrender."'
WHERE id = 20 AND title = 'The Dive Button';
UPDATE `levels` SET
  description = 'What other two-word League catchphrase does Tyler use?',
  hint = 'It rejects the idea of conceding a match.'
WHERE id = 21 AND title = 'The White-Flag Allergy';
UPDATE `levels` SET
  description = 'What five-letter gamer tag does Tyler use by default?',
  hint = 'Ask Tyler how a chef he met in Jerusalem eventually inspired the handle.'
WHERE id = 22 AND title = 'Five Letters, Zero Chill';
UPDATE `levels` SET
  description = 'What longer gamer tag does Tyler use when his default handle is unavailable?',
  hint = 'It starts with his usual five-letter handle.'
WHERE id = 23 AND title = 'Imperial Expansion Pack';
UPDATE `levels` SET
  description = 'What was the first name of the chef Tyler met while studying abroad in Jerusalem who inspired his gamer tag?',
  hint = 'Ask Tyler for the story; enter the chef''s first name.'
WHERE id = 24 AND title = 'Cafeteria Lore from Abroad';
UPDATE `levels` SET
  description = 'What three-word computer phrase did someone jokingly claim Tyler''s gamer tag could abbreviate?',
  hint = 'It sounds like an operating-system component.'
WHERE id = 25 AND title = 'Kernel Panic at the Disco';
UPDATE `levels` SET
  description = 'What is the exact title of the book Ember is writing?',
  hint = 'Ask Ember about her manuscript.'
WHERE id = 26 AND title = 'The Manuscript in Orbit';
UPDATE `levels` SET
  description = 'What is the exact name of Ryan''s Instagram and Facebook page?',
  hint = 'Ask Ryan or Tyler about Ryan''s public page.'
WHERE id = 28 AND title = 'A Very Shiny Thought';
UPDATE `levels` SET
  description = 'Which TV series supplied the names Tiger, Wolf, and the hypothetical Joosh?',
  hint = 'Ask someone who knows the family''s cat-naming joke.'
WHERE id = 33 AND title = 'Chronological Feline Nonsense';
UPDATE `levels` SET
  description = 'What is the name of the Draper import store supplying exotic snacks and prizes?',
  hint = 'The shop name uses a pirate and a breakfast-cereal pun.'
WHERE id = 35 AND title = 'Contraband Snack Run';
UPDATE `levels` SET
  description = 'Enter the university abbreviation where Ember and Tyler earned their master''s degrees.',
  hint = 'It is an online university headquartered in Utah.'
WHERE id = 36 AND title = 'Owl-Free Graduate School';
UPDATE `levels` SET
  description = 'Enter the university abbreviation where Ember and Tyler earned their bachelor''s degrees.',
  hint = 'The campus is in Provo.'
WHERE id = 37 AND title = 'Cougar Undergraduate Detour';
UPDATE `levels` SET
  description = 'Which League of Legends role does Tyler usually play when he chooses Karthus?',
  hint = 'Enter the position, not the champion.'
WHERE id = 42 AND title = 'Press R and Touch Grass';
UPDATE `levels` SET
  description = 'What is Tyler''s current favorite League of Legends champion?',
  hint = 'The champion paints spells into different combinations.'
WHERE id = 43 AND title = 'Too Many Colors';
UPDATE `levels` SET
  description = 'What is the exact title of the show Tyler likes about uploaded intelligence and the singularity?',
  hint = 'Its title names a group of gods.'
WHERE id = 52 AND title = 'Cloud Gods and Bad Decisions';
UPDATE `levels` SET
  description = 'Enter the full name of the actor who plays Ripley in Alien.',
  hint = 'Prince''s alphabet book retells that film.'
WHERE id = 59 AND title = 'Airlock Etiquette';
UPDATE `levels` SET
  description = 'What is the full title of Prince''s children''s-book retelling of Alien?',
  hint = 'It is phrased like the opening line of an alphabet book.'
WHERE id = 60 AND title = 'Bedtime on the Nostromo';
UPDATE `levels` SET
  description = 'What is the full name of the theater where Megan works as a seamstress?',
  hint = 'The name contains two first names followed by Hale Theater.'
WHERE id = 69 AND title = 'Needles under Stage Lights';
UPDATE `levels` SET
  description = 'What number of volts is the EV charger Sean Mason installed? Enter the number only.',
  hint = 'It uses the standard voltage for Level 2 home charging.',
  flag = '240'
WHERE id = 73 AND title = 'Do Not Lick the Outlet';
UPDATE `levels` SET
  description = 'What is the exact name of Natasia''s Discord server?',
  hint = 'Ask Natasia or one of its members; the name combines Tolkien with League.'
WHERE id = 74 AND title = 'One Ring, Five Stack';
UPDATE `levels` SET
  description = 'What is the exact name of the cousins'' Discord server?',
  hint = 'Ask a cousin who belongs to it.'
WHERE id = 76 AND title = 'The Cousin Crime Scene';
UPDATE `levels` SET
  description = 'What is the exact voice-channel name inside the cousins'' Discord server?',
  hint = 'Ask a member; it is a pride-themed gamer pun.'
WHERE id = 77 AND title = 'A Proudly Questionable Queue';
UPDATE `levels` SET
  description = 'What single word names Rachel''s Discord server?',
  hint = 'It is the intentionally damp misspelling from the family joke.'
WHERE id = 79 AND title = 'Perspiration by Typo';
UPDATE `levels` SET
  description = 'What is the exact voice-channel name in Rachel''s Discord server?',
  hint = 'Ask Rachel or someone who has joined that voice channel.'
WHERE id = 80 AND title = 'Voice Chat Humidity';
UPDATE `levels` SET
  description = 'Which affectionate word is deliberately misspelled as "sweaty" in the family joke?',
  hint = 'Enter the correctly spelled affectionate word.'
WHERE id = 81 AND title = 'Compliment Gone Damp';
UPDATE `levels` SET
  description = 'What is the exact name of DJ''s Discord server?',
  hint = 'Ask DJ or one of its members; the fancy server has a deliberately unfancy name.'
WHERE id = 82 AND title = 'Luxury Housing, Allegedly';
UPDATE `levels` SET
  description = 'In DJ''s reversed Discord hierarchy, what is the highest rank?',
  hint = 'Ask a member of DJ''s server; enter one rank word.'
WHERE id = 83 AND title = 'Reverse Aristocracy I';
UPDATE `levels` SET
  description = 'In DJ''s reversed Discord hierarchy, what is the lowest rank?',
  hint = 'Ask a member of DJ''s server; enter one rank word.'
WHERE id = 84 AND title = 'Reverse Aristocracy II';
UPDATE `levels` SET
  hint = 'Ask Ryan respectfully about the mission and give him time to answer with his AAC device.'
WHERE id = 85 AND title = 'Web Fluid Diplomacy';
UPDATE `levels` SET
  hint = 'Talk with Ember about her manuscript; she can choose to share the prepared code.'
WHERE id = 86 AND title = 'The Unpublished Oracle';
UPDATE `levels` SET
  hint = 'Talk with Megan about theater costume work; she can share the prepared code.'
WHERE id = 87 AND title = 'Needlework Intelligence';
UPDATE `levels` SET
  hint = 'Talk with Sean about work he has done around the house; he can share the prepared code.'
WHERE id = 88 AND title = 'Breaker-Panel Diplomacy';
UPDATE `levels` SET
  hint = 'Look among the unusual books Tyler mentioned; nothing on the shelf needs to be disturbed.'
WHERE id = 97 AND title = 'The Five-Minute Hallway';
UPDATE `levels` SET
  description = 'What controversial marriage practice makes Bengt Jonsson''s place in the family history especially complicated?',
  hint = 'The early Mormon practice involved one husband and multiple wives.',
  flag = 'POLYGAMY'
WHERE id = 104 AND title = 'The Family Tree Has Side Quests';

UPDATE `levels` l JOIN `party_copy_polish` p ON p.id = l.id AND l.title = p.new_title SET l.description = REPLACE(l.description, 'Ryans ', 'Ryan''s ');
UPDATE `levels` l JOIN `party_copy_polish` p ON p.id = l.id AND l.title = p.new_title SET l.description = REPLACE(l.description, 'Brandons ', 'Brandon''s ');
UPDATE `levels` l JOIN `party_copy_polish` p ON p.id = l.id AND l.title = p.new_title SET l.description = REPLACE(l.description, 'Kleds ', 'Kled''s ');
UPDATE `levels` l JOIN `party_copy_polish` p ON p.id = l.id AND l.title = p.new_title SET l.description = REPLACE(l.description, 'Princes ', 'Prince''s ');
UPDATE `levels` l JOIN `party_copy_polish` p ON p.id = l.id AND l.title = p.new_title SET l.description = REPLACE(l.description, 'Megans ', 'Megan''s ');
UPDATE `levels` l JOIN `party_copy_polish` p ON p.id = l.id AND l.title = p.new_title SET l.description = REPLACE(l.description, 'Embers ', 'Ember''s ');
UPDATE `levels` l JOIN `party_copy_polish` p ON p.id = l.id AND l.title = p.new_title SET l.hint = REPLACE(l.hint, 'Ryans ', 'Ryan''s ');
UPDATE `levels` l JOIN `party_copy_polish` p ON p.id = l.id AND l.title = p.new_title SET l.hint = REPLACE(l.hint, 'Kleds ', 'Kled''s ');
UPDATE `levels` l JOIN `party_copy_polish` p ON p.id = l.id AND l.title = p.new_title SET l.hint = REPLACE(l.hint, 'Princes ', 'Prince''s ');

DROP TEMPORARY TABLE `party_copy_polish`;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('20261027_manual_bases');
