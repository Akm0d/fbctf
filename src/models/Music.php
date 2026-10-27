<?hh // strict

class Music extends Model {
  const int TV_LEASE_SECONDS = 20;

  public static function extractYouTubeId(string $url): string {
    $url = trim($url);
    $parts = parse_url($url);
    invariant(is_array($parts), 'Invalid YouTube URL');
    $scheme = strtolower(strval(idx($parts, 'scheme', '')));
    $host = strtolower(strval(idx($parts, 'host', '')));
    invariant($scheme === 'https' || $scheme === 'http', 'YouTube URL must use HTTP or HTTPS');
    $host = preg_replace('/^www\./', '', $host);
    $path = trim(strval(idx($parts, 'path', '')), '/');
    $video_id = '';
    if ($host === 'youtu.be') {
      $video_id = explode('/', $path)[0];
    } else {
      invariant(
        $host === 'youtube.com' || $host === 'm.youtube.com' ||
        $host === 'music.youtube.com',
        'Only YouTube URLs are accepted',
      );
      if ($path === 'watch') {
        $query = array();
        parse_str(strval(idx($parts, 'query', '')), $query);
        $video_id = strval(idx($query, 'v', ''));
      } else {
        $segments = explode('/', $path);
        if (count($segments) >= 2 &&
            ($segments[0] === 'embed' || $segments[0] === 'shorts' ||
             $segments[0] === 'live')) {
          $video_id = $segments[1];
        }
      }
    }
    invariant(
      (bool) preg_match('/^[A-Za-z0-9_-]{11}$/', $video_id),
      'Could not find a valid YouTube video ID',
    );
    return $video_id;
  }

  public static async function genQueue(): Awaitable<array<Map<string, string>>> {
    $db = await self::genDb();
    $result = await $db->queryf(
      'SELECT q.*, COALESCE(t.name, %s) AS team_name FROM music_queue q LEFT JOIN teams t ON t.id = q.request_team_id WHERE q.status IN (%s, %s) ORDER BY q.position, q.id',
      '',
      'playing',
      'queued',
    );
    return $result->mapRows()->toArray();
  }

  public static async function genRequests(): Awaitable<array<Map<string, string>>> {
    $db = await self::genDb();
    $result = await $db->queryf(
      'SELECT r.*, t.name AS team_name FROM music_requests r INNER JOIN teams t ON t.id = r.team_id WHERE r.status NOT IN (%s, %s) ORDER BY r.position, r.id',
      'removed',
      'completed',
    );
    return $result->mapRows()->toArray();
  }

  public static async function genUnconfirmedPerformances(
  ): Awaitable<array<Map<string, string>>> {
    $db = await self::genDb();
    $result = await $db->queryf(
      'SELECT q.*, t.name AS team_name FROM music_queue q INNER JOIN teams t ON t.id = q.request_team_id LEFT JOIN karaoke_performances p ON p.queue_id = q.id WHERE q.is_karaoke = 1 AND q.status IN (%s, %s) AND p.id IS NULL ORDER BY q.finished_ts DESC, q.id DESC LIMIT 20',
      'played',
      'skipped',
    );
    return $result->mapRows()->toArray();
  }

  public static async function genRequestsForTeam(
    int $team_id,
  ): Awaitable<array<Map<string, string>>> {
    $db = await self::genDb();
    $result = await $db->queryf(
      'SELECT * FROM music_requests WHERE team_id = %d AND status <> %s ORDER BY created_ts DESC LIMIT 20',
      $team_id,
      'removed',
    );
    return $result->mapRows()->toArray();
  }

  public static async function genPlayback(): Awaitable<Map<string, string>> {
    $db = await self::genDb();
    $result = await $db->queryf(
      'SELECT p.*, COALESCE(q.youtube_id, %s) AS youtube_id, COALESCE(q.display_title, %s) AS display_title, COALESCE(q.is_karaoke, 0) AS is_karaoke, COALESCE(q.request_team_id, 0) AS request_team_id, COALESCE(t.name, %s) AS team_name FROM music_playback_state p LEFT JOIN music_queue q ON q.id = p.current_queue_id LEFT JOIN teams t ON t.id = q.request_team_id WHERE p.id = 1',
      '',
      '',
      '',
    );
    return $result->mapRows()[0];
  }

  public static async function genStage(): Awaitable<Map<string, string>> {
    $db = await self::genDb();
    $result = await $db->queryf(
      'SELECT s.*, COALESCE(t.name, %s) AS owner_team_name FROM karaoke_stage_state s LEFT JOIN teams t ON t.id = s.owner_team_id WHERE s.id = 1',
      '',
    );
    return $result->mapRows()[0];
  }

  private static async function genNextPosition(
    AsyncMysqlConnection $db,
    string $table,
  ): Awaitable<int> {
    invariant($table === 'music_queue' || $table === 'music_requests', 'Invalid position table');
    $result = await $db->query('SELECT COALESCE(MAX(position), 0) + 10 AS position FROM '.$table);
    return intval(must_have_idx($result->mapRows()[0], 'position'));
  }

  public static async function genAddTrack(
    string $youtube_id,
    string $title,
  ): Awaitable<int> {
    invariant((bool) preg_match('/^[A-Za-z0-9_-]{11}$/', $youtube_id), 'Invalid YouTube video ID');
    $title = trim($title) === '' ? 'YouTube '.$youtube_id : trim($title);
    invariant(strlen($title) <= 255, 'Song title is too long');
    $db = await self::genDb();
    $position = await self::genNextPosition($db, 'music_queue');
    await $db->queryf(
      'INSERT INTO music_queue (youtube_id, display_title, source_type, position) VALUES (%s, %s, %s, %d)',
      $youtube_id,
      $title,
      'admin',
      $position,
    );
    $result = await $db->queryf('SELECT LAST_INSERT_ID() AS id');
    return intval(must_have_idx($result->mapRows()[0], 'id'));
  }

  public static async function genRequest(
    int $team_id,
    string $youtube_id,
    string $title,
    bool $karaoke,
  ): Awaitable<void> {
    invariant((bool) preg_match('/^[A-Za-z0-9_-]{11}$/', $youtube_id), 'Invalid YouTube video ID');
    $title = trim($title) === '' ? 'YouTube '.$youtube_id : trim($title);
    invariant(strlen($title) <= 255, 'Song title is too long');
    $db = await self::genDb();
    $position = await self::genNextPosition($db, 'music_requests');
    await $db->queryf(
      'INSERT INTO music_requests (team_id, youtube_id, display_title, request_type, position) VALUES (%d, %s, %s, %s, %d)',
      $team_id,
      $youtube_id,
      $title,
      $karaoke ? 'karaoke' : 'regular',
      $position,
    );
  }

  public static async function genModerateRequest(
    int $request_id,
    string $status,
  ): Awaitable<void> {
    invariant($status === 'approved' || $status === 'rejected' || $status === 'removed', 'Invalid request status');
    $db = await self::genDb();
    await $db->queryf(
      'UPDATE music_requests SET status = %s WHERE id = %d AND status IN (%s, %s, %s)',
      $status,
      $request_id,
      'pending',
      'approved',
      'rejected',
    );
  }

  public static async function genMove(
    string $table,
    int $id,
    string $direction,
  ): Awaitable<void> {
    invariant($table === 'music_queue' || $table === 'music_requests', 'Invalid reorder target');
    invariant($direction === 'up' || $direction === 'down', 'Invalid reorder direction');
    $db = await self::genDb();
    await $db->queryf('START TRANSACTION');
    try {
      $current = await $db->query('SELECT id, position FROM '.$table.' WHERE id = '.strval($id).' FOR UPDATE');
      invariant($current->numRows() === 1, 'Item not found');
      $position = intval(must_have_idx($current->mapRows()[0], 'position'));
      $operator = $direction === 'up' ? '<' : '>';
      $order = $direction === 'up' ? 'DESC' : 'ASC';
      $other = await $db->query(
        'SELECT id, position FROM '.$table.' WHERE position '.$operator.' '.strval($position).
        ' ORDER BY position '.$order.', id '.$order.' LIMIT 1 FOR UPDATE',
      );
      if ($other->numRows() === 1) {
        $other_id = intval(must_have_idx($other->mapRows()[0], 'id'));
        $other_position = intval(must_have_idx($other->mapRows()[0], 'position'));
        await $db->query('UPDATE '.$table.' SET position = '.strval($other_position).' WHERE id = '.strval($id));
        await $db->query('UPDATE '.$table.' SET position = '.strval($position).' WHERE id = '.strval($other_id));
      }
      await $db->queryf('COMMIT');
    } catch (Exception $error) {
      await $db->queryf('ROLLBACK');
      throw $error;
    }
  }

  public static async function genScheduleRequest(
    int $request_id,
    string $mode,
  ): Awaitable<int> {
    invariant($mode === 'end' || $mode === 'next' || $mode === 'now', 'Invalid scheduling mode');
    $db = await self::genDb();
    await $db->queryf('START TRANSACTION');
    try {
      $request = await $db->queryf(
        'SELECT * FROM music_requests WHERE id = %d AND status IN (%s, %s) FOR UPDATE',
        $request_id,
        'pending',
        'approved',
      );
      invariant($request->numRows() === 1, 'Request is not available');
      $row = $request->mapRows()[0];
      $position = await self::genNextPosition($db, 'music_queue');
      if ($mode !== 'end') {
        $current = await $db->queryf('SELECT current_queue_id FROM music_playback_state WHERE id = 1 FOR UPDATE');
        $current_id = intval(idx($current->mapRows()[0], 'current_queue_id', 0));
        if ($current_id > 0) {
          $current_position_result = await $db->queryf('SELECT position FROM music_queue WHERE id = %d', $current_id);
          if ($current_position_result->numRows() === 1) {
            $position = intval(must_have_idx($current_position_result->mapRows()[0], 'position')) + 1;
          }
        } else {
          $position = 1;
        }
        await $db->queryf('UPDATE music_queue SET position = position + 1 WHERE status = %s AND position >= %d', 'queued', $position);
      }
      await $db->queryf(
        'INSERT INTO music_queue (request_id, youtube_id, display_title, request_team_id, is_karaoke, source_type, position) VALUES (%d, %s, %s, %d, %d, %s, %d)',
        $request_id,
        must_have_idx($row, 'youtube_id'),
        must_have_idx($row, 'display_title'),
        intval(must_have_idx($row, 'team_id')),
        must_have_idx($row, 'request_type') === 'karaoke' ? 1 : 0,
        'request',
        $position,
      );
      $id_result = await $db->queryf('SELECT LAST_INSERT_ID() AS id');
      $queue_id = intval(must_have_idx($id_result->mapRows()[0], 'id'));
      await $db->queryf('UPDATE music_requests SET status = %s WHERE id = %d', 'scheduled', $request_id);
      await $db->queryf('COMMIT');
      if ($mode === 'now') {
        await self::genPlayQueueItem($queue_id);
      }
      return $queue_id;
    } catch (Exception $error) {
      await $db->queryf('ROLLBACK');
      throw $error;
    }
  }

  private static async function genAdvanceLocked(
    AsyncMysqlConnection $db,
    bool $completed,
  ): Awaitable<void> {
    $state = await $db->queryf('SELECT current_queue_id FROM music_playback_state WHERE id = 1 FOR UPDATE');
    $current_id = intval(idx($state->mapRows()[0], 'current_queue_id', 0));
    if ($current_id > 0) {
      $current = await $db->queryf('SELECT request_id, is_karaoke FROM music_queue WHERE id = %d FOR UPDATE', $current_id);
      await $db->queryf(
        'UPDATE music_queue SET status = %s, finished_ts = NOW() WHERE id = %d AND status = %s',
        $completed ? 'played' : 'skipped',
        $current_id,
        'playing',
      );
      if ($completed && $current->numRows() === 1 && !boolval(must_have_idx($current->mapRows()[0], 'is_karaoke'))) {
        $request_id = intval(idx($current->mapRows()[0], 'request_id', 0));
        if ($request_id > 0) {
          await $db->queryf('UPDATE music_requests SET status = %s WHERE id = %d', 'completed', $request_id);
        }
      }
    }
    $next = await $db->queryf(
      'SELECT id FROM music_queue WHERE status = %s ORDER BY position, id LIMIT 1 FOR UPDATE',
      'queued',
    );
    if ($next->numRows() === 1) {
      $next_id = intval(must_have_idx($next->mapRows()[0], 'id'));
      await $db->queryf('UPDATE music_queue SET status = %s, started_ts = NOW() WHERE id = %d', 'playing', $next_id);
      await $db->queryf(
        'UPDATE music_playback_state SET current_queue_id = %d, desired_state = %s, command_version = command_version + 1 WHERE id = 1',
        $next_id,
        'playing',
      );
    } else {
      await $db->queryf(
        'UPDATE music_playback_state SET current_queue_id = NULL, desired_state = %s, command_version = command_version + 1 WHERE id = 1',
        'paused',
      );
    }
  }

  public static async function genPlaybackCommand(string $command): Awaitable<void> {
    invariant($command === 'play' || $command === 'pause' || $command === 'skip', 'Invalid playback command');
    $db = await self::genDb();
    await $db->queryf('START TRANSACTION');
    try {
      if ($command === 'skip') {
        await self::genAdvanceLocked($db, false);
      } else {
        $state = await $db->queryf('SELECT current_queue_id FROM music_playback_state WHERE id = 1 FOR UPDATE');
        $current_id = intval(idx($state->mapRows()[0], 'current_queue_id', 0));
        if ($command === 'play' && $current_id === 0) {
          await self::genAdvanceLocked($db, false);
        } else {
          await $db->queryf(
            'UPDATE music_playback_state SET desired_state = %s, command_version = command_version + 1 WHERE id = 1',
            $command === 'play' ? 'playing' : 'paused',
          );
        }
      }
      await $db->queryf('COMMIT');
    } catch (Exception $error) {
      await $db->queryf('ROLLBACK');
      throw $error;
    }
  }

  public static async function genPlayQueueItem(int $queue_id): Awaitable<void> {
    $db = await self::genDb();
    await $db->queryf('START TRANSACTION');
    try {
      $state = await $db->queryf('SELECT current_queue_id FROM music_playback_state WHERE id = 1 FOR UPDATE');
      $old_id = intval(idx($state->mapRows()[0], 'current_queue_id', 0));
      $item = await $db->queryf('SELECT id FROM music_queue WHERE id = %d AND status IN (%s, %s) FOR UPDATE', $queue_id, 'queued', 'playing');
      invariant($item->numRows() === 1, 'Queue item is not playable');
      if ($old_id > 0 && $old_id !== $queue_id) {
        await $db->queryf('UPDATE music_queue SET status = %s, finished_ts = NOW() WHERE id = %d AND status = %s', 'skipped', $old_id, 'playing');
      }
      await $db->queryf('UPDATE music_queue SET status = %s, started_ts = COALESCE(started_ts, NOW()) WHERE id = %d', 'playing', $queue_id);
      await $db->queryf('UPDATE music_playback_state SET current_queue_id = %d, desired_state = %s, command_version = command_version + 1 WHERE id = 1', $queue_id, 'playing');
      await $db->queryf('COMMIT');
    } catch (Exception $error) {
      await $db->queryf('ROLLBACK');
      throw $error;
    }
  }

  public static async function genRemoveQueueItem(int $queue_id): Awaitable<void> {
    $state = await self::genPlayback();
    if (intval(idx($state, 'current_queue_id', 0)) === $queue_id) {
      await self::genPlaybackCommand('skip');
    }
    $db = await self::genDb();
    await $db->queryf('UPDATE music_queue SET status = %s WHERE id = %d AND status IN (%s, %s)', 'removed', $queue_id, 'queued', 'skipped');
  }

  private static function validTvKey(string $key): bool {
    return (bool) preg_match('/^[a-f0-9]{32,64}$/', $key);
  }

  public static async function genClaimTv(string $key): Awaitable<bool> {
    invariant(self::validTvKey($key), 'Invalid TV key');
    $hash = hash('sha256', $key);
    $db = await self::genDb();
    $result = await $db->queryf(
      'UPDATE music_playback_state SET active_tv_key_hash = %s, active_tv_lease_until = DATE_ADD(NOW(), INTERVAL %d SECOND) WHERE id = 1 AND (active_tv_key_hash = %s OR active_tv_lease_until IS NULL OR active_tv_lease_until < NOW())',
      $hash,
      self::TV_LEASE_SECONDS,
      $hash,
    );
    return $result->numRowsAffected() === 1;
  }

  public static async function genTvFinished(
    string $key,
    int $queue_id,
  ): Awaitable<bool> {
    invariant(self::validTvKey($key), 'Invalid TV key');
    $hash = hash('sha256', $key);
    $db = await self::genDb();
    await $db->queryf('START TRANSACTION');
    try {
      $state = await $db->queryf(
        'SELECT current_queue_id FROM music_playback_state WHERE id = 1 AND active_tv_key_hash = %s AND active_tv_lease_until >= NOW() FOR UPDATE',
        $hash,
      );
      if ($state->numRows() !== 1 || intval(idx($state->mapRows()[0], 'current_queue_id', 0)) !== $queue_id) {
        await $db->queryf('COMMIT');
        return false;
      }
      await self::genAdvanceLocked($db, true);
      await $db->queryf('COMMIT');
      return true;
    } catch (Exception $error) {
      await $db->queryf('ROLLBACK');
      throw $error;
    }
  }

  public static async function genAccrueStage(): Awaitable<int> {
    $db = await self::genDb();
    await $db->queryf('START TRANSACTION');
    try {
      $stage_result = await $db->queryf('SELECT * FROM karaoke_stage_state WHERE id = 1 FOR UPDATE');
      $stage = $stage_result->mapRows()[0];
      $owner_id = intval(idx($stage, 'owner_team_id', 0));
      if ($owner_id === 0) {
        await $db->queryf('COMMIT');
        return 0;
      }
      $config = await $db->queryf(
        'SELECT field, value FROM configuration WHERE field IN (%s, %s, %s)',
        'game',
        'game_paused',
        'scoring',
      );
      $values = Map {};
      foreach ($config->mapRows() as $row) {
        $values[must_have_idx($row, 'field')] = must_have_idx($row, 'value');
      }
      $active = boolval(must_have_idx($stage, 'enabled')) &&
        idx($values, 'game') === '1' && idx($values, 'game_paused') === '0' &&
        idx($values, 'scoring') === '1';
      $last_value = strval(idx($stage, 'last_accrual_ts', ''));
      if (!$active || $last_value === '') {
        await $db->queryf('UPDATE karaoke_stage_state SET last_accrual_ts = NOW() WHERE id = 1');
        await $db->queryf('COMMIT');
        return 0;
      }
      $last = intval(strtotime($last_value));
      $minutes = (int) floor((time() - $last) / 60);
      if ($minutes <= 0) {
        await $db->queryf('COMMIT');
        return 0;
      }
      $points = $minutes * intval(must_have_idx($stage, 'passive_points_per_minute'));
      $version = intval(must_have_idx($stage, 'ownership_version'));
      $key = 'stage:'.strval($version).':'.strval($last).':'.strval($minutes);
      $ledger = await $db->queryf(
        'INSERT IGNORE INTO music_points_ledger (team_id, reward_type, points, idempotency_key) VALUES (%d, %s, %d, %s)',
        $owner_id,
        'stage',
        $points,
        $key,
      );
      if ($ledger->numRowsAffected() === 1 && $points > 0) {
        await $db->queryf('UPDATE teams SET points = points + %d, last_score = NOW() WHERE id = %d', $points, $owner_id);
      }
      $next = date('Y-m-d H:i:s', $last + ($minutes * 60));
      await $db->queryf('UPDATE karaoke_stage_state SET last_accrual_ts = %s WHERE id = 1', $next);
      await $db->queryf('COMMIT');
      MultiTeam::invalidateMCRecords();
      return $points;
    } catch (Exception $error) {
      await $db->queryf('ROLLBACK');
      throw $error;
    }
  }

  public static async function genMarkStageCursorNow(): Awaitable<void> {
    $db = await self::genDb();
    await $db->queryf(
      'UPDATE karaoke_stage_state SET last_accrual_ts = IF(owner_team_id IS NULL, NULL, NOW()) WHERE id = 1',
    );
  }

  public static async function genResetStageOwnership(): Awaitable<void> {
    $db = await self::genDb();
    await $db->queryf(
      'UPDATE karaoke_stage_state SET owner_team_id = NULL, ownership_version = ownership_version + 1, last_accrual_ts = NULL, captured_ts = NULL WHERE id = 1',
    );
  }

  public static async function genConfirmPerformance(
    int $queue_id,
    int $admin_team_id,
  ): Awaitable<bool> {
    await self::genAccrueStage();
    $db = await self::genDb();
    await $db->queryf('START TRANSACTION');
    try {
      $item_result = await $db->queryf(
        'SELECT q.*, r.id AS linked_request_id FROM music_queue q LEFT JOIN music_requests r ON r.id = q.request_id WHERE q.id = %d AND q.is_karaoke = 1 AND q.request_team_id IS NOT NULL AND q.status IN (%s, %s, %s) FOR UPDATE',
        $queue_id,
        'playing',
        'played',
        'skipped',
      );
      invariant($item_result->numRows() === 1, 'Karaoke queue item not found');
      $existing = await $db->queryf('SELECT id FROM karaoke_performances WHERE queue_id = %d FOR UPDATE', $queue_id);
      if ($existing->numRows() === 1) {
        await $db->queryf('COMMIT');
        return false;
      }
      $item = $item_result->mapRows()[0];
      $stage_result = await $db->queryf('SELECT * FROM karaoke_stage_state WHERE id = 1 FOR UPDATE');
      $stage = $stage_result->mapRows()[0];
      $config = await $db->queryf(
        'SELECT COUNT(*) AS active FROM configuration WHERE (field = %s AND value = %s) OR (field = %s AND value = %s) OR (field = %s AND value = %s)',
        'game',
        '1',
        'game_paused',
        '0',
        'scoring',
        '1',
      );
      invariant(
        intval(must_have_idx($config->mapRows()[0], 'active')) === 3,
        'Performances can only be completed while the game and scoring are active',
      );
      $team_id = intval(must_have_idx($item, 'request_team_id'));
      $points = intval(must_have_idx($stage, 'performance_points'));
      await $db->queryf(
        'INSERT INTO karaoke_performances (queue_id, request_id, team_id, youtube_id, display_title, admin_team_id, points) VALUES (%d, NULLIF(%d, 0), %d, %s, %s, %d, %d)',
        $queue_id,
        intval(idx($item, 'linked_request_id', 0)),
        $team_id,
        must_have_idx($item, 'youtube_id'),
        must_have_idx($item, 'display_title'),
        $admin_team_id,
        $points,
      );
      $performance_result = await $db->queryf('SELECT LAST_INSERT_ID() AS id');
      $performance_id = intval(must_have_idx($performance_result->mapRows()[0], 'id'));
      $ledger = await $db->queryf(
        'INSERT IGNORE INTO music_points_ledger (team_id, performance_id, reward_type, points, idempotency_key) VALUES (%d, %d, %s, %d, %s)',
        $team_id,
        $performance_id,
        'performance',
        $points,
        'performance:'.strval($queue_id),
      );
      if ($ledger->numRowsAffected() === 1 && $points > 0) {
        await $db->queryf('UPDATE teams SET points = points + %d, last_score = NOW() WHERE id = %d', $points, $team_id);
      }
      $old_owner = intval(idx($stage, 'owner_team_id', 0));
      if ($old_owner !== $team_id) {
        await $db->queryf(
          'UPDATE karaoke_stage_state SET owner_team_id = %d, ownership_version = ownership_version + 1, captured_ts = NOW(), last_accrual_ts = NOW() WHERE id = 1',
          $team_id,
        );
      } else {
        await $db->queryf('UPDATE karaoke_stage_state SET captured_ts = NOW() WHERE id = 1');
      }
      $request_id = intval(idx($item, 'linked_request_id', 0));
      if ($request_id > 0) {
        await $db->queryf('UPDATE music_requests SET status = %s WHERE id = %d', 'completed', $request_id);
      }
      await $db->queryf('COMMIT');
      await ActivityLog::genCreateGenericLog(
        '%s completed karaoke: '.must_have_idx($item, 'display_title'),
        'Team:'.strval($team_id),
      );
      MultiTeam::invalidateMCRecords();
      return true;
    } catch (Exception $error) {
      await $db->queryf('ROLLBACK');
      throw $error;
    }
  }

  public static async function genConfigure(
    int $performance_points,
    int $passive_points,
    bool $enabled,
    int $activity_country,
    int $stage_country,
    int $category_id,
  ): Awaitable<void> {
    invariant($performance_points >= 0 && $passive_points >= 0, 'Points cannot be negative');
    invariant($activity_country === 0 || $stage_country === 0 || $activity_country !== $stage_country, 'Activity and stage need different countries');
    $stage = await self::genStage();
    $activity_level_id = intval(idx($stage, 'activity_level_id', 0));
    $stage_level_id = intval(idx($stage, 'stage_level_id', 0));
    if ($activity_country > 0 && $category_id > 0) {
      if ($activity_level_id === 0) {
        $activity_level_id = await Level::genCreate('actv', 'Karaoke Performance', 'Request a karaoke song, perform it, and have an administrator confirm completion.', $activity_country, $category_id, $performance_points, 0, 0, 0, '', '', 0);
      } else {
        await Level::genUpdate('Karaoke Performance', 'Request a karaoke song, perform it, and have an administrator confirm completion.', $activity_country, $category_id, $performance_points, 0, 0, 0, '', '', 0, $activity_level_id);
      }
      await Level::genSetStatus($activity_level_id, $enabled);
    }
    if ($stage_country > 0 && $category_id > 0) {
      if ($stage_level_id === 0) {
        $stage_level_id = await Level::genCreate('stag', 'Karaoke Stage', 'The most recent karaoke team controls the stage and earns passive points.', $stage_country, $category_id, $passive_points, 0, 0, 0, '', '', 0);
      } else {
        await Level::genUpdate('Karaoke Stage', 'The most recent karaoke team controls the stage and earns passive points.', $stage_country, $category_id, $passive_points, 0, 0, 0, '', '', 0, $stage_level_id);
      }
      await Level::genSetStatus($stage_level_id, $enabled);
    }
    $db = await self::genDb();
    await $db->queryf(
      'UPDATE karaoke_stage_state SET activity_level_id = NULLIF(%d, 0), stage_level_id = NULLIF(%d, 0), performance_points = %d, passive_points_per_minute = %d, enabled = %d WHERE id = 1',
      $activity_level_id,
      $stage_level_id,
      $performance_points,
      $passive_points,
      $enabled ? 1 : 0,
    );
  }
}
