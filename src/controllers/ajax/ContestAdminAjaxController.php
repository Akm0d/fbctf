<?hh // strict

class ContestAdminAjaxController extends AjaxController {
  <<__Override>>
  protected function getFilters(): array<string, mixed> {
    return array(
      'POST' => array(
        'action' => FILTER_UNSAFE_RAW,
        'csrf_token' => FILTER_UNSAFE_RAW,
        'contest_id' => FILTER_VALIDATE_INT,
        'entry_id' => FILTER_VALIDATE_INT,
        'title' => FILTER_UNSAFE_RAW,
        'description' => FILTER_UNSAFE_RAW,
        'entity_id' => FILTER_VALIDATE_INT,
        'category_id' => FILTER_VALIDATE_INT,
        'submission_type' => FILTER_UNSAFE_RAW,
        'vote_points' => FILTER_VALIDATE_INT,
        'winner_points' => FILTER_VALIDATE_INT,
        'voting_open' => FILTER_UNSAFE_RAW,
        'voting_close' => FILTER_UNSAFE_RAW,
        'visible' => FILTER_VALIDATE_BOOLEAN,
        'active' => FILTER_VALIDATE_BOOLEAN,
      ),
    );
  }

  <<__Override>>
  protected function getActions(): array<string> {
    return array(
      'create_contest',
      'update_contest',
      'finalize_contest',
      'delete_contest',
    );
  }

  private function normalizeDate(string $value): string {
    $value = trim($value);
    if ($value === '') {
      return '';
    }
    $timestamp = strtotime($value);
    invariant($timestamp !== false, 'Invalid date or time');
    return date('Y-m-d H:i:s', $timestamp);
  }

  private async function genSaveContest(
    array<string, mixed> $params,
    bool $create,
  ): Awaitable<void> {
    $title = trim(must_have_string($params, 'title'));
    invariant($title !== '' && strlen($title) <= 200, 'Title is required');
    $description = must_have_string($params, 'description');
    $entity_id = must_have_int($params, 'entity_id');
    $category_id = intval(idx($params, 'category_id', 0));
    invariant($category_id > 0, 'Choose a category');
    $submission_type = must_have_string($params, 'submission_type');
    $vote_points = intval(idx($params, 'vote_points', 0));
    $winner_points = intval(idx($params, 'winner_points', 0));
    $voting_open = $this->normalizeDate(strval(idx($params, 'voting_open', '')));
    $voting_close = $this->normalizeDate(strval(idx($params, 'voting_close', '')));
    $visible = boolval(idx($params, 'visible', false));
    $active = boolval(idx($params, 'active', false));
    if ($create) {
      await Contest::genCreate(
        $title, $description, $entity_id, $category_id, $submission_type,
        $vote_points, $winner_points,
        $voting_open, $voting_close, $visible, $active,
      );
    } else {
      await Contest::genUpdate(
        must_have_int($params, 'contest_id'),
        $title, $description, $entity_id, $category_id, $submission_type,
        $vote_points, $winner_points,
        $voting_open, $voting_close, $visible, $active,
      );
    }
  }

  <<__Override>>
  protected async function genHandleAction(
    string $action,
    array<string, mixed> $params,
  ): Awaitable<string> {
    if (idx($params, 'csrf_token') !== SessionUtils::CSRFToken()) {
      return Utils::error_response('CSRF token is invalid', 'admin');
    }
    try {
      switch ($action) {
        case 'create_contest':
          await $this->genSaveContest($params, true);
          return Utils::ok_response('Contest created', 'admin');
        case 'update_contest':
          await $this->genSaveContest($params, false);
          return Utils::ok_response('Contest updated', 'admin');
        case 'finalize_contest':
          $awarded = await Contest::genFinalize(
            must_have_int($params, 'contest_id'),
            must_have_int($params, 'entry_id'),
          );
          return Utils::ok_response(
            $awarded ? 'Winner finalized and points awarded' : 'Contest was already finalized',
            'admin',
          );
        case 'delete_contest':
          await Contest::genDelete(must_have_int($params, 'contest_id'));
          return Utils::ok_response('Contest deleted', 'admin');
        default:
          return Utils::error_response('Invalid action', 'admin');
      }
    } catch (Exception $error) {
      return Utils::error_response($error->getMessage(), 'admin');
    }
  }
}
