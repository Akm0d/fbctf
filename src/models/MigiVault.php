<?hh // strict

class MigiVault extends Model {

  const string CATEGORY = 'Migi';

  private static function normalizeCode(string $code): string {
    return strval(preg_replace('/[^A-Z0-9]+/', '', strtoupper(trim($code))));
  }

  private static function newCode(): string {
    $hex = strtoupper(bin2hex(random_bytes(8)));
    return 'MIGI-'.implode('-', str_split($hex, 4));
  }

  public static async function genIsVault(int $level_id): Awaitable<bool> {
    $db = await self::genDb();
    $result = await $db->queryf(
      'SELECT l.id FROM levels l JOIN categories c ON c.id = l.category_id WHERE l.id = %d AND l.type = %s AND c.category = %s LIMIT 1',
      $level_id,
      'base',
      self::CATEGORY,
    );
    return $result->numRows() === 1;
  }

  public static async function genInitialize(int $level_id): Awaitable<bool> {
    $db = await self::genDb();
    await $db->queryf('START TRANSACTION');
    try {
      $result = await $db->queryf(
        'SELECT l.id, l.flag FROM levels l JOIN categories c ON c.id = l.category_id WHERE l.id = %d AND l.type = %s AND c.category = %s FOR UPDATE',
        $level_id,
        'base',
        self::CATEGORY,
      );
      if ($result->numRows() !== 1) {
        await $db->queryf('COMMIT');
        return false;
      }
      await $db->queryf(
        'INSERT IGNORE INTO manual_base_state (level_id) VALUES (%d)',
        $level_id,
      );
      $flag = strval(must_have_idx($result->mapRows()[0], 'flag'));
      if ($flag === '') {
        await $db->queryf(
          'UPDATE levels SET flag = %s WHERE id = %d LIMIT 1',
          self::newCode(),
          $level_id,
        );
      }
      await $db->queryf('COMMIT');
      Level::invalidateMCRecords();
      ManualBase::invalidateMCRecords();
      return true;
    } catch (Exception $error) {
      await $db->queryf('ROLLBACK');
      throw $error;
    }
  }

  public static async function genDetach(int $level_id): Awaitable<void> {
    $db = await self::genDb();
    await $db->queryf(
      'DELETE FROM manual_base_state WHERE level_id = %d LIMIT 1',
      $level_id,
    );
    ManualBase::invalidateMCRecords();
  }

  public static async function genSecretState(
    int $level_id,
  ): Awaitable<(string, int)> {
    $initialized = await self::genInitialize($level_id);
    invariant($initialized, 'This is not Migi\'s Vault');
    $db = await self::genDb();
    $result = await $db->queryf(
      'SELECT l.flag, s.ownership_version FROM levels l JOIN manual_base_state s ON s.level_id = l.id WHERE l.id = %d LIMIT 1',
      $level_id,
    );
    invariant($result->numRows() === 1, 'Migi\'s Vault is not initialized');
    $row = $result->mapRows()[0];
    return tuple(
      must_have_idx($row, 'flag'),
      intval(must_have_idx($row, 'ownership_version')),
    );
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

  public static async function genCapture(
    int $level_id,
    int $team_id,
    string $submitted_code,
  ): Awaitable<string> {
    $initialized = await self::genInitialize($level_id);
    invariant($initialized, 'This is not Migi\'s Vault');
    await ManualBase::genAccrue($level_id);
    $db = await self::genDb();
    await $db->queryf('START TRANSACTION');
    try {
      $result = await $db->queryf(
        'SELECT l.flag, l.active, l.bonus, s.owner_team_id, s.ownership_version FROM levels l JOIN categories c ON c.id = l.category_id JOIN manual_base_state s ON s.level_id = l.id WHERE l.id = %d AND l.type = %s AND c.category = %s FOR UPDATE',
        $level_id,
        'base',
        self::CATEGORY,
      );
      invariant($result->numRows() === 1, 'Migi\'s Vault is unavailable');
      $state = $result->mapRows()[0];
      $scoring_active = await self::genScoringActive($db);
      if (!boolval(must_have_idx($state, 'active')) || !$scoring_active) {
        await $db->queryf('COMMIT');
        return 'inactive';
      }
      $current_code = must_have_idx($state, 'flag');
      if ($current_code === '' ||
          self::normalizeCode($current_code) !== self::normalizeCode($submitted_code)) {
        await $db->queryf('COMMIT');
        return 'stale';
      }
      $owner_id = intval(idx($state, 'owner_team_id', 0));
      if ($owner_id === $team_id) {
        await $db->queryf('COMMIT');
        return 'owned';
      }

      $version = intval(must_have_idx($state, 'ownership_version')) + 1;
      $points = intval(must_have_idx($state, 'bonus'));
      $ledger_key =
        'migi-vault:'.strval($level_id).':capture:'.strval($version);
      $ledger = await $db->queryf(
        'INSERT IGNORE INTO manual_base_points_ledger (level_id, team_id, ownership_version, reward_type, points, idempotency_key) VALUES (%d, %d, %d, %s, %d, %s)',
        $level_id,
        $team_id,
        $version,
        'capture',
        $points,
        $ledger_key,
      );
      invariant($ledger->numRowsAffected() === 1, 'Capture was already credited');
      await $db->queryf(
        'UPDATE manual_base_state SET owner_team_id = %d, ownership_version = %d, last_accrual_ts = NOW(), captured_ts = NOW(), updated_by_admin_id = NULL WHERE level_id = %d',
        $team_id,
        $version,
        $level_id,
      );
      await $db->queryf(
        'UPDATE levels SET flag = %s WHERE id = %d LIMIT 1',
        self::newCode(),
        $level_id,
      );
      if ($points > 0) {
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
      }
      await $db->queryf('COMMIT');

      Level::invalidateMCRecords();
      ManualBase::invalidateMCRecords();
      ScoreLog::invalidateMCRecords();
      MultiTeam::invalidateMCRecords();
      ActivityLog::invalidateMCRecords();
      try {
        await ActivityLog::genCaptureLog($team_id, $level_id);
      } catch (Exception $error) {
        error_log('Migi Vault capture activity log failed');
      }
      return 'captured';
    } catch (Exception $error) {
      await $db->queryf('ROLLBACK');
      throw $error;
    }
  }
}
