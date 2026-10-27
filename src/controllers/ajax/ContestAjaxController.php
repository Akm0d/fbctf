<?hh // strict

class ContestAjaxController extends AjaxController {
  <<__Override>>
  protected function getFilters(): array<string, mixed> {
    return array(
      'POST' => array(
        'action' => FILTER_UNSAFE_RAW,
        'csrf_token' => FILTER_UNSAFE_RAW,
        'contest_id' => FILTER_VALIDATE_INT,
        'entry_id' => FILTER_VALIDATE_INT,
        'submission_type' => FILTER_UNSAFE_RAW,
        'text_content' => FILTER_UNSAFE_RAW,
      ),
    );
  }

  <<__Override>>
  protected function getActions(): array<string> {
    return array(
      'submit_entry',
      'vote',
    );
  }

  <<__Override>>
  protected async function genHandleAction(
    string $action,
    array<string, mixed> $params,
  ): Awaitable<string> {
    if (idx($params, 'csrf_token') !== SessionUtils::CSRFToken()) {
      return Utils::error_response('CSRF token is invalid', 'contests');
    }
    $new_image = '';
    try {
      switch ($action) {
        case 'submit_entry':
          $contest_id = must_have_int($params, 'contest_id');
          $submission_type = must_have_string($params, 'submission_type');
          if ($submission_type === 'image') {
            $upload = Utils::getFILES()->get('contest_image');
            invariant($upload !== null, 'Choose an image first');
            $content = Contest::storeUploadedImage($upload);
            $new_image = $content;
          } else {
            $content = must_have_string($params, 'text_content');
          }
          await Contest::genSaveEntry(
            $contest_id,
            SessionUtils::sessionTeam(),
            $submission_type,
            $content,
          );
          return Utils::ok_response('Team entry saved', 'contests');
        case 'vote':
          $earned = await Contest::genVote(
            must_have_int($params, 'contest_id'),
            must_have_int($params, 'entry_id'),
            SessionUtils::sessionTeam(),
            hash('sha256', session_id()),
          );
          return Utils::ok_response(
            $earned ? 'Vote saved and team points awarded' : 'Vote updated; points were already awarded',
            'contests',
          );
        default:
          return Utils::error_response('Invalid action', 'contests');
      }
    } catch (Exception $error) {
      if ($new_image !== '') {
        $path = Contest::IMAGE_DIR.basename($new_image);
        if (is_file($path)) {
          unlink($path);
        }
      }
      return Utils::error_response($error->getMessage(), 'contests');
    }
  }
}
