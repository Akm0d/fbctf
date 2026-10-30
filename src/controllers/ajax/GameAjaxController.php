<?hh // strict

class GameAjaxController extends AjaxController {
  <<__Override>>
  protected function getFilters(): array<string, mixed> {
    return array(
      'POST' => array(
        'level_id' => FILTER_VALIDATE_INT,
        'answer' => FILTER_UNSAFE_RAW,
        'csrf_token' => FILTER_UNSAFE_RAW,
        'livesync_username' => FILTER_UNSAFE_RAW,
        'livesync_password' => FILTER_UNSAFE_RAW,
        'team_name' => FILTER_UNSAFE_RAW,
        'action' => array(
          'filter' => FILTER_VALIDATE_REGEXP,
          'options' => array('regexp' => '/^[\w-]+$/'),
        ),
        'page' => array(
          'filter' => FILTER_VALIDATE_REGEXP,
          'options' => array('regexp' => '/^[\w-]+$/'),
        ),
      ),
    );
  }

  <<__Override>>
  protected function getActions(): array<string> {
    return array('answer_level', 'get_hint', 'open_level');
  }

  private static async function genFailedAnswer(
    int $level_id,
    int $team_id,
    string $answer,
  ): Awaitable<string> {
    list($_, $penalty) = await \HH\Asio\va(
      FailureLog::genLogFailedScore($level_id, $team_id, $answer),
      Level::genPenalizeFailure($level_id, $team_id),
    );
    $message = $penalty > 0
      ? 'Failed (-'.strval($penalty).' points)'
      : 'Failed';
    return Utils::error_response($message, 'game');
  }

  private static async function genAnswerLevel(
    array<string, mixed> $params,
  ): Awaitable<string> {
    $scoring = await Configuration::gen('scoring');
    if ($scoring->getValue() !== '1') {
      return Utils::error_response('Failed', 'game');
    }

    $level_id = must_have_int($params, 'level_id');
    $answer = must_have_string($params, 'answer');
    $team_id = SessionUtils::sessionTeam();
    $team_can_access = await Level::genTeamCanAccess($level_id, $team_id);
    if (!$team_can_access) {
      return Utils::error_response('Challenge is locked', 'game');
    }
    $is_migi_vault = await MigiVault::genIsVault($level_id);
    if ($is_migi_vault) {
      $capture = await MigiVault::genCapture($level_id, $team_id, $answer);
      if ($capture === 'captured') {
        return Utils::ok_response('Success', 'game');
      }
      if ($capture === 'owned') {
        return Utils::error_response('Your team already owns this base', 'game');
      }
      if ($capture === 'stale') {
        return await self::genFailedAnswer($level_id, $team_id, $answer);
      }
      return Utils::error_response('Failed', 'game');
    }

    list($check_base, $check_status, $check_answer) = await \HH\Asio\va(
      Level::genCheckBase($level_id),
      Level::genCheckStatus($level_id),
      Level::genCheckAnswer($level_id, $answer),
    );
    if ($check_base || !$check_status) {
      return Utils::error_response('Failed', 'game');
    }
    if ($check_answer) {
      $check_answered = await Level::genScoreLevel($level_id, $team_id);
      if (!$check_answered) {
        return Utils::ok_response('Double score for you! SIKE!', 'game');
      }
      $capture_message = await Level::genCaptureMessage($level_id);
      return Utils::ok_response($capture_message, 'game');
    }
    return await self::genFailedAnswer($level_id, $team_id, $answer);
  }

  <<__Override>>
  protected async function genHandleAction(
    string $action,
    array<string, mixed> $params,
  ): Awaitable<string> {
    if ($action !== 'none') {
      // CSRF check
      if (idx($params, 'csrf_token') !== SessionUtils::CSRFToken()) {
        return Utils::error_response('CSRF token is invalid', 'game');
      }
    }

    switch ($action) {
      case 'none':
        return Utils::error_response('Invalid action', 'game');
      case 'answer_level':
        return await self::genAnswerLevel($params);
      case 'get_hint':
        $requested_hint = await Level::genLevelHint(
          must_have_int($params, 'level_id'),
          SessionUtils::sessionTeam(),
        );
        if ($requested_hint !== null) {
          MultiTeam::invalidateMCRecords('ALL_TEAMS'); // Invalidate Memcached MultiTeam data.
          MultiTeam::invalidateMCRecords('POINTS_BY_TYPE'); // Invalidate Memcached MultiTeam data.
          MultiTeam::invalidateMCRecords('LEADERBOARD'); // Invalidate Memcached MultiTeam data.
          return Utils::hint_response($requested_hint, 'OK');
        } else {
          return Utils::hint_response('', 'ERROR');
        }
      case 'open_level':
        return Utils::ok_response('Success', 'admin');
      case 'set_team_name':
        $updated_team_name = await Team::genSetTeamName(
          SessionUtils::sessionTeam(),
          must_have_string($params, 'team_name'),
        );
        if ($updated_team_name === true) {
          return Utils::ok_response('Success', 'game');
        } else {
          return Utils::error_response('Failed', 'game');
        }
      case 'set_livesync_password':
        $livesync_password_update = await Team::genSetLiveSyncPassword(
          SessionUtils::sessionTeam(),
          "fbctf",
          must_have_string($params, 'livesync_username'),
          must_have_string($params, 'livesync_password'),
        );
        if ($livesync_password_update === true) {
          return Utils::ok_response('Success', 'game');
        } else {
          return Utils::error_response('Failed', 'game');
        }
      default:
        return Utils::error_response('Invalid action', 'game');
    }
  }
}
