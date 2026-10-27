<?hh // strict

class ManualBase extends Model {

  protected static string $MC_KEY = 'manualbase:';

  public static async function genLevelIds(): Awaitable<array<int>> {
    $db = await self::genDb();
    $result = await $db->queryf('SELECT level_id FROM manual_base_state');
    return $result->mapRows()
      ->map($row ==> intval(must_have_idx($row, 'level_id')))
      ->toArray();
  }

  public static async function genIsManual(int $level_id): Awaitable<bool> {
    $ids = await self::genLevelIds();
    return in_array($level_id, $ids, true);
  }

  public static async function genOwner(
    int $level_id,
  ): Awaitable<(int, string)> {
    $db = await self::genDb();
    $result = await $db->queryf(
      'SELECT COALESCE(MAX(s.owner_team_id), 0) AS owner_team_id, COALESCE(MAX(t.name), %s) AS owner_team_name FROM manual_base_state s LEFT JOIN teams t ON t.id = s.owner_team_id WHERE s.level_id = %d',
      '',
      $level_id,
    );
    $row = $result->mapRows()[0];
    return tuple(
      intval(must_have_idx($row, 'owner_team_id')),
      must_have_idx($row, 'owner_team_name'),
    );
  }

  public static async function genOwnerName(
    int $level_id,
  ): Awaitable<?string> {
    $is_manual = await self::genIsManual($level_id);
    if (!$is_manual) {
      return null;
    }
    list($_, $owner_name) = await self::genOwner($level_id);
    return $owner_name;
  }

  private static async function genScoringActive(
    AsyncMysqlConnection $db,
  ): Awaitable<bool> {
    $result = await $db->queryf(
      'SELECT COUNT(*) AS active FROM configuration WHERE (field = %s AND value = %s) OR (field = %s AND value = %s) OR (field = %s AND value = %s)',
      'game',
      '1',
      'game_paused',
      '0',
      'scoring',
      '1',
    );
    return intval(must_have_idx($result->mapRows()[0], 'active')) === 3;
  }

  private static async function genCreditLocked(
    AsyncMysqlConnection $db,
    int $level_id,
    int $team_id,
    int $version,
    string $reward_type,
    int $points,
    string $key,
  ): Awaitable<bool> {
    if ($points <= 0) {
      return false;
    }
    $ledger = await $db->queryf(
      'INSERT IGNORE INTO manual_base_points_ledger (level_id, team_id, ownership_version, reward_type, points, idempotency_key) VALUES (%d, %d, %d, %s, %d, %s)',
      $level_id,
      $team_id,
      $version,
      $reward_type,
      $points,
      $key,
    );
    if ($ledger->numRowsAffected() !== 1) {
      return false;
    }
    await $db->queryf(
      'UPDATE teams SET points = points + %d, last_score = NOW() WHERE id = %d LIMIT 1',
      $points,
      $team_id,
    );
    await $db->queryf(
      'INSERT INTO scores_log (ts, level_id, team_id, points, type) VALUES (NOW(), %d, %d, %d, %s) ON DUPLICATE KEY UPDATE ts = NOW(), points = points + VALUES(points), type = VALUES(type)',
      $level_id,
      $team_id,
      $points,
      'base',
    );
    return true;
  }

  public static async function genAccrue(int $level_id): Awaitable<int> {
    $db = await self::genDb();
    await $db->queryf('START TRANSACTION');
    try {
      $result = await $db->queryf(
        'SELECT s.*, l.active, l.points FROM manual_base_state s JOIN levels l ON l.id = s.level_id WHERE s.level_id = %d FOR UPDATE',
        $level_id,
      );
      if ($result->numRows() !== 1) {
        await $db->queryf('COMMIT');
        return 0;
      }
      $state = $result->mapRows()[0];
      $owner_id = intval(idx($state, 'owner_team_id', 0));
      $last_value = strval(idx($state, 'last_accrual_ts', ''));
      $scoring_active = await self::genScoringActive($db);
      $active = boolval(must_have_idx($state, 'active')) && $scoring_active;
      if ($owner_id === 0) {
        await $db->queryf('COMMIT');
        return 0;
      }
      if (!$active || $last_value === '') {
        await $db->queryf(
          'UPDATE manual_base_state SET last_accrual_ts = NOW() WHERE level_id = %d',
          $level_id,
        );
        await $db->queryf('COMMIT');
        return 0;
      }
      $last = intval(strtotime($last_value));
      $seconds = max(1, intval(must_have_idx($state, 'accrual_seconds')));
      $intervals = (int) floor((time() - $last) / $seconds);
      if ($intervals <= 0) {
        await $db->queryf('COMMIT');
        return 0;
      }
      $points = $intervals * intval(must_have_idx($state, 'points'));
      $version = intval(must_have_idx($state, 'ownership_version'));
      $key = 'manual-base:'.strval($level_id).':hold:'.strval($version).':'.strval($last).':'.strval($intervals);
      await self::genCreditLocked(
        $db,
        $level_id,
        $owner_id,
        $version,
        'hold',
        $points,
        $key,
      );
      $next = date('Y-m-d H:i:s', $last + ($intervals * $seconds));
      await $db->queryf(
        'UPDATE manual_base_state SET last_accrual_ts = %s WHERE level_id = %d',
        $next,
        $level_id,
      );
      await $db->queryf('COMMIT');
      MultiTeam::invalidateMCRecords();
      ScoreLog::invalidateMCRecords();
      return $points;
    } catch (Exception $error) {
      await $db->queryf('ROLLBACK');
      throw $error;
    }
  }

  public static async function genAccrueAll(): Awaitable<int> {
    $ids = await self::genLevelIds();
    $points = 0;
    foreach ($ids as $level_id) {
      $level_points = await self::genAccrue($level_id);
      $points += $level_points;
    }
    return $points;
  }

  public static async function genSetOwner(
    int $level_id,
    int $team_id,
    int $admin_team_id,
  ): Awaitable<bool> {
    await self::genAccrue($level_id);
    $db = await self::genDb();
    await $db->queryf('START TRANSACTION');
    try {
      $result = await $db->queryf(
        'SELECT s.*, l.bonus FROM manual_base_state s JOIN levels l ON l.id = s.level_id WHERE s.level_id = %d FOR UPDATE',
        $level_id,
      );
      invariant($result->numRows() === 1, 'Manual base not found');
      if ($team_id > 0) {
        $team = await $db->queryf(
          'SELECT id FROM teams WHERE id = %d AND active = 1 AND visible = 1 LIMIT 1',
          $team_id,
        );
        invariant($team->numRows() === 1, 'Playable team not found');
      }
      $state = $result->mapRows()[0];
      $old_owner = intval(idx($state, 'owner_team_id', 0));
      if ($old_owner === $team_id) {
        await $db->queryf('COMMIT');
        return false;
      }
      $version = intval(must_have_idx($state, 'ownership_version')) + 1;
      if ($team_id === 0) {
        await $db->queryf(
          'UPDATE manual_base_state SET owner_team_id = NULL, ownership_version = %d, last_accrual_ts = NULL, captured_ts = NULL, updated_by_admin_id = %d WHERE level_id = %d',
          $version,
          $admin_team_id,
          $level_id,
        );
      } else {
        await $db->queryf(
          'UPDATE manual_base_state SET owner_team_id = %d, ownership_version = %d, last_accrual_ts = NOW(), captured_ts = NOW(), updated_by_admin_id = %d WHERE level_id = %d',
          $team_id,
          $version,
          $admin_team_id,
          $level_id,
        );
        $scoring_active = await self::genScoringActive($db);
        if ($scoring_active) {
          await self::genCreditLocked(
            $db,
            $level_id,
            $team_id,
            $version,
            'capture',
            intval(must_have_idx($state, 'bonus')),
            'manual-base:'.strval($level_id).':capture:'.strval($version),
          );
        }
      }
      await $db->queryf('COMMIT');
      $owner = $team_id === 0 ? 'Unclaimed' : 'Team:'.strval($team_id);
      await ActivityLog::genCreateGenericLog(
        'Manual base ownership changed to %s',
        $owner,
      );
      MultiTeam::invalidateMCRecords();
      ScoreLog::invalidateMCRecords();
      Level::invalidateMCRecords();
      return true;
    } catch (Exception $error) {
      await $db->queryf('ROLLBACK');
      throw $error;
    }
  }

  public static async function genResetOwners(): Awaitable<void> {
    await self::genUpdateOwnershipState(true);
  }

  public static async function genMarkCursorsNow(): Awaitable<void> {
    await self::genUpdateOwnershipState(false);
  }

  private static async function genUpdateOwnershipState(
    bool $reset,
  ): Awaitable<void> {
    $db = await self::genDb();
    if ($reset) {
      await $db->queryf(
        'UPDATE manual_base_state SET owner_team_id = NULL, ownership_version = ownership_version + 1, last_accrual_ts = NULL, captured_ts = NULL, updated_by_admin_id = NULL',
      );
    } else {
      await $db->queryf(
        'UPDATE manual_base_state SET last_accrual_ts = IF(owner_team_id IS NULL, NULL, NOW())',
      );
    }
  }
}
