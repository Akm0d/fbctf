<?hh // strict

class MusicAdminAjaxController extends AjaxController {
  <<__Override>>
  protected function getFilters(): array<string, mixed> {
    return array(
      'POST' => array(
        'action' => FILTER_UNSAFE_RAW,
        'csrf_token' => FILTER_UNSAFE_RAW,
        'playlist_text' => FILTER_UNSAFE_RAW,
        'request_id' => FILTER_VALIDATE_INT,
        'queue_id' => FILTER_VALIDATE_INT,
        'status' => FILTER_UNSAFE_RAW,
        'mode' => FILTER_UNSAFE_RAW,
        'direction' => FILTER_UNSAFE_RAW,
        'command' => FILTER_UNSAFE_RAW,
        'performance_points' => FILTER_VALIDATE_INT,
        'passive_points' => FILTER_VALIDATE_INT,
        'enabled' => FILTER_VALIDATE_BOOLEAN,
        'activity_country' => FILTER_VALIDATE_INT,
        'stage_country' => FILTER_VALIDATE_INT,
        'category_id' => FILTER_VALIDATE_INT,
      ),
    );
  }

  <<__Override>>
  protected function getActions(): array<string> {
    return array(
      'add_playlist', 'moderate_request', 'schedule_request',
      'move_queue', 'move_request', 'remove_queue', 'play_queue',
      'playback', 'confirm_performance', 'configure_karaoke',
    );
  }

  private async function genAddPlaylist(string $text): Awaitable<int> {
    $count = 0;
    foreach (preg_split('/\r?\n/', trim($text)) as $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      $title = '';
      $url = $line;
      if (strpos($line, '|') !== false) {
        list($title, $url) = explode('|', $line, 2);
      }
      $youtube_id = Music::extractYouTubeId(trim($url));
      await Music::genAddTrack($youtube_id, trim($title));
      $count++;
    }
    invariant($count > 0, 'Paste at least one YouTube URL');
    return $count;
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
        case 'add_playlist':
          $count = await $this->genAddPlaylist(must_have_string($params, 'playlist_text'));
          return Utils::ok_response(strval($count).' songs added', 'admin');
        case 'moderate_request':
          await Music::genModerateRequest(must_have_int($params, 'request_id'), must_have_string($params, 'status'));
          return Utils::ok_response('Request updated', 'admin');
        case 'schedule_request':
          await Music::genScheduleRequest(must_have_int($params, 'request_id'), must_have_string($params, 'mode'));
          return Utils::ok_response('Request added to the playing queue', 'admin');
        case 'move_queue':
          await Music::genMove('music_queue', must_have_int($params, 'queue_id'), must_have_string($params, 'direction'));
          return Utils::ok_response('Queue reordered', 'admin');
        case 'move_request':
          await Music::genMove('music_requests', must_have_int($params, 'request_id'), must_have_string($params, 'direction'));
          return Utils::ok_response('Requests reordered', 'admin');
        case 'remove_queue':
          await Music::genRemoveQueueItem(must_have_int($params, 'queue_id'));
          return Utils::ok_response('Song removed', 'admin');
        case 'play_queue':
          await Music::genPlayQueueItem(must_have_int($params, 'queue_id'));
          return Utils::ok_response('Song sent to the TV', 'admin');
        case 'playback':
          await Music::genPlaybackCommand(must_have_string($params, 'command'));
          return Utils::ok_response('Playback command sent', 'admin');
        case 'confirm_performance':
          $awarded = await Music::genConfirmPerformance(
            must_have_int($params, 'queue_id'),
            SessionUtils::sessionTeam(),
          );
          return Utils::ok_response(
            $awarded ? 'Performance completed and points awarded' : 'Performance was already completed',
            'admin',
          );
        case 'configure_karaoke':
          await Music::genConfigure(
            must_have_int($params, 'performance_points'),
            must_have_int($params, 'passive_points'),
            boolval(idx($params, 'enabled', false)),
            intval(idx($params, 'activity_country', 0)),
            intval(idx($params, 'stage_country', 0)),
            intval(idx($params, 'category_id', 0)),
          );
          return Utils::ok_response('Karaoke configuration saved', 'admin');
        default:
          return Utils::error_response('Invalid action', 'admin');
      }
    } catch (Exception $error) {
      return Utils::error_response($error->getMessage(), 'admin');
    }
  }
}
