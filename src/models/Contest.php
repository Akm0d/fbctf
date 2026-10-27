<?hh // strict

class Contest extends Model {
  const string IMAGE_DIR = '/var/www/fbctf/attachments/contests/';
  const int MAX_IMAGE_BYTES = 10485760;

  public static async function genAll(
    bool $visible_only = false,
    int $level_id = 0,
  ): Awaitable<array<Map<string, string>>> {
    $db = await self::genDb();
    if ($visible_only && $level_id > 0) {
      $result = await $db->queryf(
        'SELECT co.*, COALESCE(cat.category, %s) AS category, COALESCE(country.iso_code, %s) AS country_name, COALESCE(l.entity_id, 0) AS entity_id, COALESCE(w.team_id, 0) AS winning_team_id, COALESCE(t.name, %s) AS winning_team_name FROM contests co LEFT JOIN levels l ON l.id = co.level_id LEFT JOIN countries country ON country.id = l.entity_id LEFT JOIN categories cat ON cat.id = COALESCE(l.category_id, co.category_id) LEFT JOIN contest_entries w ON w.id = co.winning_entry_id LEFT JOIN teams t ON t.id = w.team_id WHERE co.visible = 1 AND co.level_id = %d ORDER BY co.created_ts DESC, co.id DESC',
        'Uncategorized',
        '',
        '',
        $level_id,
      );
    } else if ($visible_only) {
      $result = await $db->queryf(
        'SELECT co.*, COALESCE(cat.category, %s) AS category, COALESCE(country.iso_code, %s) AS country_name, COALESCE(l.entity_id, 0) AS entity_id, COALESCE(w.team_id, 0) AS winning_team_id, COALESCE(t.name, %s) AS winning_team_name FROM contests co LEFT JOIN levels l ON l.id = co.level_id LEFT JOIN countries country ON country.id = l.entity_id LEFT JOIN categories cat ON cat.id = COALESCE(l.category_id, co.category_id) LEFT JOIN contest_entries w ON w.id = co.winning_entry_id LEFT JOIN teams t ON t.id = w.team_id WHERE co.visible = 1 ORDER BY co.created_ts DESC, co.id DESC',
        'Uncategorized',
        '',
        '',
      );
    } else {
      $result = await $db->queryf(
        'SELECT co.*, COALESCE(cat.category, %s) AS category, COALESCE(country.iso_code, %s) AS country_name, COALESCE(l.entity_id, 0) AS entity_id, COALESCE(w.team_id, 0) AS winning_team_id, COALESCE(t.name, %s) AS winning_team_name FROM contests co LEFT JOIN levels l ON l.id = co.level_id LEFT JOIN countries country ON country.id = l.entity_id LEFT JOIN categories cat ON cat.id = COALESCE(l.category_id, co.category_id) LEFT JOIN contest_entries w ON w.id = co.winning_entry_id LEFT JOIN teams t ON t.id = w.team_id ORDER BY co.created_ts DESC, co.id DESC',
        'Uncategorized',
        '',
        '',
      );
    }
    return $result->mapRows()->toArray();
  }

  public static async function genOne(
    int $contest_id,
  ): Awaitable<?Map<string, string>> {
    $db = await self::genDb();
    $result = await $db->queryf(
      'SELECT co.*, COALESCE(cat.category, %s) AS category, COALESCE(country.iso_code, %s) AS country_name, COALESCE(l.entity_id, 0) AS entity_id, COALESCE(w.team_id, 0) AS winning_team_id, COALESCE(t.name, %s) AS winning_team_name FROM contests co LEFT JOIN levels l ON l.id = co.level_id LEFT JOIN countries country ON country.id = l.entity_id LEFT JOIN categories cat ON cat.id = COALESCE(l.category_id, co.category_id) LEFT JOIN contest_entries w ON w.id = co.winning_entry_id LEFT JOIN teams t ON t.id = w.team_id WHERE co.id = %d LIMIT 1',
      'Uncategorized',
      '',
      '',
      $contest_id,
    );
    if ($result->numRows() !== 1) {
      return null;
    }
    return $result->mapRows()[0];
  }

  public static async function genEntries(
    int $contest_id,
  ): Awaitable<array<Map<string, string>>> {
    $db = await self::genDb();
    $result = await $db->queryf(
      'SELECT e.*, t.name AS team_name, COUNT(v.id) AS vote_count FROM contest_entries e INNER JOIN teams t ON t.id = e.team_id LEFT JOIN contest_session_votes v ON v.entry_id = e.id WHERE e.contest_id = %d GROUP BY e.id, t.name ORDER BY vote_count DESC, e.updated_ts ASC',
      $contest_id,
    );
    return $result->mapRows()->toArray();
  }

  public static async function genEntryForTeam(
    int $contest_id,
    int $team_id,
  ): Awaitable<?Map<string, string>> {
    $db = await self::genDb();
    $result = await $db->queryf(
      'SELECT * FROM contest_entries WHERE contest_id = %d AND team_id = %d LIMIT 1',
      $contest_id,
      $team_id,
    );
    return $result->numRows() === 1 ? $result->mapRows()[0] : null;
  }

  public static async function genVoteForSession(
    int $contest_id,
    string $session_key,
  ): Awaitable<?Map<string, string>> {
    $db = await self::genDb();
    $result = await $db->queryf(
      'SELECT * FROM contest_session_votes WHERE contest_id = %d AND voter_session_key = %s LIMIT 1',
      $contest_id,
      $session_key,
    );
    return $result->numRows() === 1 ? $result->mapRows()[0] : null;
  }

  public static async function genCreate(
    string $title,
    string $description,
    int $entity_id,
    int $category_id,
    string $submission_type,
    int $vote_points,
    int $winner_points,
    string $voting_open,
    string $voting_close,
    bool $visible,
    bool $active,
  ): Awaitable<int> {
    invariant($submission_type === 'text' || $submission_type === 'image', 'Invalid submission type');
    invariant($vote_points >= 0 && $winner_points >= 0, 'Points cannot be negative');
    invariant($entity_id > 0, 'Choose a country for the contest');
    $level_id = await Level::genCreate(
      'poll', trim($title), trim($description), $entity_id, $category_id,
      0, 0, 0, 0, '', '', 0,
    );
    await Level::genSetStatus($level_id, $visible && $active);
    $db = await self::genDb();
    await $db->queryf(
      'INSERT INTO contests (level_id, title, description, category_id, submission_type, vote_points, winner_points, submission_open_ts, submission_close_ts, voting_open_ts, voting_close_ts, visible, active) VALUES (%d, %s, %s, NULLIF(%d, 0), %s, %d, %d, NULL, NULL, NULLIF(%s, %s), NULLIF(%s, %s), %d, %d)',
      $level_id,
      trim($title),
      trim($description),
      $category_id,
      $submission_type,
      $vote_points,
      $winner_points,
      $voting_open,
      '',
      $voting_close,
      '',
      $visible ? 1 : 0,
      $active ? 1 : 0,
    );
    $result = await $db->queryf('SELECT LAST_INSERT_ID() AS id');
    return intval(must_have_idx($result->mapRows()[0], 'id'));
  }

  public static async function genUpdate(
    int $contest_id,
    string $title,
    string $description,
    int $entity_id,
    int $category_id,
    string $submission_type,
    int $vote_points,
    int $winner_points,
    string $voting_open,
    string $voting_close,
    bool $visible,
    bool $active,
  ): Awaitable<void> {
    invariant($submission_type === 'text' || $submission_type === 'image', 'Invalid submission type');
    invariant($vote_points >= 0 && $winner_points >= 0, 'Points cannot be negative');
    invariant($entity_id > 0, 'Choose a country for the contest');
    $db = await self::genDb();
    $current = await $db->queryf(
      'SELECT level_id FROM contests WHERE id = %d AND finalized = 0 LIMIT 1',
      $contest_id,
    );
    invariant($current->numRows() === 1, 'Contest is finalized or missing');
    $level_id = intval(idx($current->mapRows()[0], 'level_id', 0));
    if ($level_id === 0) {
      $level_id = await Level::genCreate(
        'poll', trim($title), trim($description), $entity_id, $category_id,
        0, 0, 0, 0, '', '', 0,
      );
    } else {
      await Level::genUpdate(
        trim($title), trim($description), $entity_id, $category_id,
        0, 0, 0, 0, '', '', 0, $level_id,
      );
    }
    await Level::genSetStatus($level_id, $visible && $active);
    await $db->queryf(
      'UPDATE contests SET level_id = %d, title = %s, description = %s, category_id = NULLIF(%d, 0), submission_type = %s, vote_points = %d, winner_points = %d, submission_open_ts = NULL, submission_close_ts = NULL, voting_open_ts = NULLIF(%s, %s), voting_close_ts = NULLIF(%s, %s), visible = %d, active = %d WHERE id = %d AND finalized = 0',
      $level_id,
      trim($title),
      trim($description),
      $category_id,
      $submission_type,
      $vote_points,
      $winner_points,
      $voting_open,
      '',
      $voting_close,
      '',
      $visible ? 1 : 0,
      $active ? 1 : 0,
      $contest_id,
    );
  }

  private static async function genRequireSubmissionOpen(
    AsyncMysqlConnection $db,
    int $contest_id,
    string $submission_type,
  ): Awaitable<void> {
    $game_running = await self::genGameRunning();
    invariant($game_running, 'Submissions are open only while the game is running');
    $result = await $db->queryf(
      'SELECT id FROM contests WHERE id = %d AND active = 1 AND visible = 1 AND finalized = 0 AND submission_type = %s LIMIT 1',
      $contest_id,
      $submission_type,
    );
    invariant($result->numRows() === 1, 'Submissions are closed');
  }

  public static async function genGameRunning(): Awaitable<bool> {
    list($game, $end) = await \HH\Asio\va(
      Configuration::gen('game'),
      Configuration::gen('end_ts'),
    );
    $end_ts = intval($end->getValue());
    return $game->getValue() === '1' && ($end_ts === 0 || time() < $end_ts);
  }

  public static async function genSaveEntry(
    int $contest_id,
    int $team_id,
    string $submission_type,
    string $content,
  ): Awaitable<void> {
    $db = await self::genDb();
    await self::genRequireSubmissionOpen($db, $contest_id, $submission_type);
    if ($submission_type === 'text') {
      $text = trim($content);
      invariant(strlen($text) > 0 && strlen($text) <= 5000, 'Text entry must be 1-5000 characters');
      await $db->queryf(
        'INSERT INTO contest_entries (contest_id, team_id, text_content, image_filename) VALUES (%d, %d, %s, NULL) ON DUPLICATE KEY UPDATE text_content = VALUES(text_content), updated_ts = NOW()',
        $contest_id,
        $team_id,
        $text,
      );
    } else {
      invariant((bool) preg_match('/^[a-f0-9]{32}\.(jpg|png|gif|webp)$/', $content), 'Invalid image filename');
      $existing = await self::genEntryForTeam($contest_id, $team_id);
      $old_filename = $existing === null ? '' : strval(idx($existing, 'image_filename', ''));
      await $db->queryf(
        'INSERT INTO contest_entries (contest_id, team_id, text_content, image_filename) VALUES (%d, %d, NULL, %s) ON DUPLICATE KEY UPDATE image_filename = VALUES(image_filename), updated_ts = NOW()',
        $contest_id,
        $team_id,
        $content,
      );
      if ($old_filename !== '' && $old_filename !== $content) {
        $old_path = self::IMAGE_DIR.basename($old_filename);
        if (is_file($old_path)) {
          unlink($old_path);
        }
      }
    }
  }

  public static function storeUploadedImage(
    array<string, mixed> $upload,
  ): string {
    $error = intval(idx($upload, 'error', UPLOAD_ERR_NO_FILE));
    $size = intval(idx($upload, 'size', 0));
    $tmp_name = strval(idx($upload, 'tmp_name', ''));
    invariant($error === UPLOAD_ERR_OK, 'Image upload failed');
    invariant($size > 0 && $size <= self::MAX_IMAGE_BYTES, 'Image must be 10 MB or smaller');
    invariant(is_uploaded_file($tmp_name), 'Invalid image upload');

    $image_info = getimagesize($tmp_name);
    invariant($image_info !== false, 'Uploaded file is not an image');
    $mime = strval(idx($image_info, 'mime', ''));
    $extensions = array(
      'image/jpeg' => 'jpg',
      'image/png' => 'png',
      'image/gif' => 'gif',
      'image/webp' => 'webp',
    );
    invariant(array_key_exists($mime, $extensions), 'Unsupported image type');
    $extension = must_have_idx($extensions, $mime);
    $filename = bin2hex(random_bytes(16)).'.'.$extension;
    if (!is_dir(self::IMAGE_DIR)) {
      mkdir(self::IMAGE_DIR, 0750, true);
    }
    invariant(
      move_uploaded_file($tmp_name, self::IMAGE_DIR.$filename),
      'Could not store uploaded image',
    );
    chmod(self::IMAGE_DIR.$filename, 0640);
    return $filename;
  }

  public static async function genVote(
    int $contest_id,
    int $entry_id,
    int $team_id,
    string $session_key,
  ): Awaitable<bool> {
    $db = await self::genDb();
    await $db->queryf('START TRANSACTION');
    try {
      $contest_result = await $db->queryf(
        'SELECT vote_points FROM contests WHERE id = %d AND active = 1 AND visible = 1 AND finalized = 0 AND (voting_open_ts IS NULL OR voting_open_ts <= NOW()) AND (voting_close_ts IS NULL OR voting_close_ts > NOW()) FOR UPDATE',
        $contest_id,
      );
      invariant($contest_result->numRows() === 1, 'Voting is closed');
      $entry_result = await $db->queryf(
        'SELECT team_id FROM contest_entries WHERE id = %d AND contest_id = %d FOR UPDATE',
        $entry_id,
        $contest_id,
      );
      invariant($entry_result->numRows() === 1, 'Contest entry not found');
      invariant(
        intval(must_have_idx($entry_result->mapRows()[0], 'team_id')) !== $team_id,
        'You cannot vote for your own team',
      );

      $existing = await $db->queryf(
        'SELECT id FROM contest_session_votes WHERE contest_id = %d AND voter_session_key = %s FOR UPDATE',
        $contest_id,
        $session_key,
      );
      if ($existing->numRows() === 1) {
        await $db->queryf(
          'UPDATE contest_session_votes SET entry_id = %d, updated_ts = NOW() WHERE contest_id = %d AND voter_session_key = %s',
          $entry_id,
          $contest_id,
          $session_key,
        );
        await $db->queryf('COMMIT');
        return false;
      }

      $team_vote_count = await $db->queryf(
        'SELECT COUNT(*) AS vote_count FROM contest_session_votes WHERE contest_id = %d AND voter_team_id = %d',
        $contest_id,
        $team_id,
      );
      $team_vote_limit = await $db->queryf(
        'SELECT COALESCE(NULLIF((SELECT COUNT(*) FROM teams_data WHERE team_id = %d), 0), NULLIF((SELECT CAST(value AS UNSIGNED) FROM configuration WHERE field = %s LIMIT 1), 0), 1) AS vote_limit',
        $team_id,
        'registration_players',
      );
      $vote_limit = intval(
        must_have_idx($team_vote_limit->mapRows()[0], 'vote_limit'),
      );
      invariant(
        intval(must_have_idx($team_vote_count->mapRows()[0], 'vote_count')) < $vote_limit,
        'This team already used all %d configured voting sessions',
        $vote_limit,
      );
      await $db->queryf(
        'INSERT INTO contest_session_votes (contest_id, voter_session_key, voter_team_id, entry_id) VALUES (%d, %s, %d, %d)',
        $contest_id,
        $session_key,
        $team_id,
        $entry_id,
      );
      $vote_points = intval(must_have_idx($contest_result->mapRows()[0], 'vote_points'));
      $key = 'vote-session:'.strval($contest_id).':'.$session_key;
      $ledger = await $db->queryf(
        'INSERT IGNORE INTO contest_points_ledger (contest_id, team_id, entry_id, reward_type, points, idempotency_key) VALUES (%d, %d, %d, %s, %d, %s)',
        $contest_id,
        $team_id,
        $entry_id,
        'vote',
        $vote_points,
        $key,
      );
      if ($ledger->numRowsAffected() === 1 && $vote_points > 0) {
        await $db->queryf(
          'UPDATE teams SET points = points + %d, last_score = NOW() WHERE id = %d',
          $vote_points,
          $team_id,
        );
      }
      await $db->queryf('COMMIT');
      MultiTeam::invalidateMCRecords();
      return true;
    } catch (Exception $error) {
      await $db->queryf('ROLLBACK');
      throw $error;
    }
  }

  public static async function genFinalize(
    int $contest_id,
    int $entry_id,
  ): Awaitable<bool> {
    $db = await self::genDb();
    await $db->queryf('START TRANSACTION');
    try {
      $contest_result = await $db->queryf(
        'SELECT winner_points, finalized, level_id FROM contests WHERE id = %d AND (active = 0 OR (voting_close_ts IS NOT NULL AND voting_close_ts <= NOW())) FOR UPDATE',
        $contest_id,
      );
      invariant(
        $contest_result->numRows() === 1,
        'Contest must be closed before a winner can be finalized',
      );
      if (boolval(must_have_idx($contest_result->mapRows()[0], 'finalized'))) {
        await $db->queryf('COMMIT');
        return false;
      }
      $entry_result = await $db->queryf(
        'SELECT team_id FROM contest_entries WHERE id = %d AND contest_id = %d FOR UPDATE',
        $entry_id,
        $contest_id,
      );
      invariant($entry_result->numRows() === 1, 'Winning entry not found');
      $team_id = intval(must_have_idx($entry_result->mapRows()[0], 'team_id'));
      $winner_points = intval(must_have_idx($contest_result->mapRows()[0], 'winner_points'));
      $ledger = await $db->queryf(
        'INSERT IGNORE INTO contest_points_ledger (contest_id, team_id, entry_id, reward_type, points, idempotency_key) VALUES (%d, %d, %d, %s, %d, %s)',
        $contest_id,
        $team_id,
        $entry_id,
        'winner',
        $winner_points,
        'winner:'.strval($contest_id),
      );
      if ($ledger->numRowsAffected() === 1 && $winner_points > 0) {
        await $db->queryf(
          'UPDATE teams SET points = points + %d, last_score = NOW() WHERE id = %d',
          $winner_points,
          $team_id,
        );
      }
      await $db->queryf(
        'UPDATE contests SET finalized = 1, active = 0, winning_entry_id = %d WHERE id = %d',
        $entry_id,
        $contest_id,
      );
      await $db->queryf('COMMIT');
      $level_id = intval(idx($contest_result->mapRows()[0], 'level_id', 0));
      if ($level_id > 0) {
        await Level::genSetStatus($level_id, false);
      }
      MultiTeam::invalidateMCRecords();
      return true;
    } catch (Exception $error) {
      await $db->queryf('ROLLBACK');
      throw $error;
    }
  }

  public static async function genDelete(int $contest_id): Awaitable<void> {
    $db = await self::genDb();
    $image_filenames = array();
    await $db->queryf('START TRANSACTION');
    try {
      $contest_result = await $db->queryf(
        'SELECT level_id FROM contests WHERE id = %d FOR UPDATE',
        $contest_id,
      );
      invariant($contest_result->numRows() === 1, 'Contest not found');
      $level_id = intval(idx($contest_result->mapRows()[0], 'level_id', 0));

      $images = await $db->queryf(
        'SELECT image_filename FROM contest_entries WHERE contest_id = %d AND image_filename IS NOT NULL',
        $contest_id,
      );
      foreach ($images->mapRows() as $image) {
        $image_filenames[] = strval(must_have_idx($image, 'image_filename'));
      }

      $ledger = await $db->queryf(
        'SELECT team_id, points FROM contest_points_ledger WHERE contest_id = %d FOR UPDATE',
        $contest_id,
      );
      $points_by_team = Map {};
      foreach ($ledger->mapRows() as $reward) {
        $team_id = intval(must_have_idx($reward, 'team_id'));
        $points = intval(must_have_idx($reward, 'points'));
        $points_by_team->set($team_id, intval(idx($points_by_team, $team_id, 0)) + $points);
      }
      foreach ($points_by_team as $team_id => $points) {
        if ($points !== 0) {
          await $db->queryf(
            'UPDATE teams SET points = points - %d, last_score = NOW() WHERE id = %d',
            $points,
            $team_id,
          );
        }
      }

      await $db->queryf(
        'DELETE FROM contest_session_votes WHERE contest_id = %d',
        $contest_id,
      );
      await $db->queryf(
        'DELETE FROM contest_points_ledger WHERE contest_id = %d',
        $contest_id,
      );
      await $db->queryf(
        'DELETE FROM contest_entries WHERE contest_id = %d',
        $contest_id,
      );
      await $db->queryf('DELETE FROM contests WHERE id = %d', $contest_id);
      if ($level_id > 0) {
        await $db->queryf('DELETE FROM failures_log WHERE level_id = %d', $level_id);
        await $db->queryf('DELETE FROM hints_log WHERE level_id = %d', $level_id);
        await $db->queryf('DELETE FROM scores_log WHERE level_id = %d', $level_id);
        await $db->queryf('DELETE FROM levels WHERE id = %d', $level_id);
      }
      await $db->queryf('COMMIT');
    } catch (Exception $error) {
      await $db->queryf('ROLLBACK');
      throw $error;
    }

    foreach ($image_filenames as $filename) {
      $path = self::IMAGE_DIR.basename($filename);
      if (is_file($path)) {
        unlink($path);
      }
    }
    await Country::genUsedAdjust();
    await ActivityLog::genAdminLog('deleted', 'Contest', $contest_id);
    Level::invalidateMCRecords();
    Control::invalidateMCRecords();
    MultiTeam::invalidateMCRecords();
    HintLog::invalidateMCRecords();
    ScoreLog::invalidateMCRecords();
  }
}
