<?hh // strict

class MigiAjaxController extends AjaxController {
  <<__Override>>
  protected function getFilters(): array<string, mixed> {
    return array(
      'POST' => array(
        'action' => FILTER_UNSAFE_RAW,
        'csrf_token' => FILTER_UNSAFE_RAW,
        'level_id' => FILTER_VALIDATE_INT,
        'message' => FILTER_UNSAFE_RAW,
      ),
    );
  }

  <<__Override>>
  protected function getActions(): array<string> {
    return array('history', 'send', 'clear');
  }

  private function response(
    string $result,
    string $message,
    int $level_id,
  ): string {
    return json_encode(array(
      'result' => $result,
      'message' => $message,
      'history' => MigiChat::history($level_id),
    ));
  }

  <<__Override>>
  protected async function genHandleAction(
    string $action,
    array<string, mixed> $params,
  ): Awaitable<string> {
    if (idx($params, 'csrf_token') !== SessionUtils::CSRFToken()) {
      return Utils::error_response('CSRF token is invalid', 'game');
    }
    try {
      $level_id = must_have_int($params, 'level_id');
      await MigiChat::genAssertChallenge($level_id);
      switch ($action) {
        case 'history':
          return $this->response('OK', '', $level_id);
        case 'clear':
          MigiChat::clear($level_id);
          return $this->response('OK', 'Conversation cleared', $level_id);
        case 'send':
          $reply = MigiChat::send(
            $level_id,
            strval(idx($params, 'message', '')),
          );
          return $this->response('OK', $reply, $level_id);
        default:
          return Utils::error_response('Invalid action', 'game');
      }
    } catch (Exception $error) {
      return Utils::error_response($error->getMessage(), 'game');
    }
  }
}
