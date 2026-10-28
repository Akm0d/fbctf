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

  private async function genResponse(
    string $result,
    string $message,
    int $level_id,
  ): Awaitable<string> {
    $history = await MigiChat::genHistory($level_id);
    return json_encode(array(
      'result' => $result,
      'message' => $message,
      'history' => $history,
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
          return await $this->genResponse('OK', '', $level_id);
        case 'clear':
          await MigiChat::genClear($level_id);
          return await $this->genResponse('OK', 'Conversation cleared', $level_id);
        case 'send':
          $reply = await MigiChat::genSend(
            $level_id,
            strval(idx($params, 'message', '')),
          );
          return await $this->genResponse('OK', $reply, $level_id);
        default:
          return Utils::error_response('Invalid action', 'game');
      }
    } catch (Exception $error) {
      return Utils::error_response($error->getMessage(), 'game');
    }
  }
}
