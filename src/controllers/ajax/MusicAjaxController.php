<?hh // strict

class MusicAjaxController extends AjaxController {
  <<__Override>>
  protected function getFilters(): array<string, mixed> {
    return array(
      'POST' => array(
        'action' => FILTER_UNSAFE_RAW,
        'csrf_token' => FILTER_UNSAFE_RAW,
        'youtube_url' => FILTER_UNSAFE_RAW,
        'display_title' => FILTER_UNSAFE_RAW,
        'request_type' => FILTER_UNSAFE_RAW,
      ),
    );
  }

  <<__Override>>
  protected function getActions(): array<string> {
    return array('request_song');
  }

  <<__Override>>
  protected async function genHandleAction(
    string $action,
    array<string, mixed> $params,
  ): Awaitable<string> {
    if (idx($params, 'csrf_token') !== SessionUtils::CSRFToken()) {
      return Utils::error_response('CSRF token is invalid', 'music');
    }
    try {
      invariant($action === 'request_song', 'Invalid action');
      $request_type = must_have_string($params, 'request_type');
      invariant($request_type === 'regular' || $request_type === 'karaoke', 'Invalid request type');
      $youtube_id = Music::extractYouTubeId(must_have_string($params, 'youtube_url'));
      await Music::genRequest(
        SessionUtils::sessionTeam(),
        $youtube_id,
        strval(idx($params, 'display_title', '')),
        $request_type === 'karaoke',
      );
      return Utils::ok_response('Song request sent to the hosts', 'music');
    } catch (Exception $error) {
      return Utils::error_response($error->getMessage(), 'music');
    }
  }
}
