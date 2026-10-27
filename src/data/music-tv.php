<?hh // strict

require_once ($_SERVER['DOCUMENT_ROOT'].'/../vendor/autoload.php');

function musicTvJson(mixed $data): void {
  header('Content-Type: application/json');
  header('Cache-Control: no-store');
  print json_encode($data);
}

async function genMusicTvData(): Awaitable<void> {
  $method = strval(idx(Utils::getSERVER(), 'REQUEST_METHOD', 'GET'));
  $client_key = strval(idx($method === 'POST' ? Utils::getPOST() : Utils::getGET(), 'client_key', ''));
  if ($method === 'POST') {
    $action = strval(idx(Utils::getPOST(), 'action', ''));
    if ($action !== 'finished') {
      http_response_code(400);
      musicTvJson(array('result' => 'ERROR'));
      return;
    }
    try {
      $advanced = await Music::genTvFinished(
        $client_key,
        intval(idx(Utils::getPOST(), 'queue_id', 0)),
      );
      musicTvJson(array('result' => $advanced ? 'OK' : 'IGNORED'));
    } catch (Exception $error) {
      http_response_code(400);
      musicTvJson(array('result' => 'ERROR'));
    }
    return;
  }

  try {
    $active_tv = await Music::genClaimTv($client_key);
  } catch (Exception $_) {
    $active_tv = false;
  }
  list($playback, $queue, $stage, $leaders, $activity, $announcements) = await \HH\Asio\va(
    Music::genPlayback(),
    Music::genQueue(),
    Music::genStage(),
    MultiTeam::genLeaderboard(false),
    ActivityLog::genAllActivity(true),
    Announcement::genAllAnnouncements(true),
  );
  $leaderboard = array();
  $rank = 1;
  foreach ($leaders as $team) {
    if ($rank > 10) {
      break;
    }
    $leaderboard[] = array(
      'rank' => $rank,
      'name' => $team->getName(),
      'points' => $team->getPoints(),
    );
    $rank++;
  }
  $activity_data = array();
  foreach (array_slice($activity, 0, 8) as $item) {
    $message = $item->getFormattedMessage();
    if ($message === '') {
      $message = trim($item->getFormattedSubject().' '.$item->getAction().' '.$item->getFormattedEntity());
    }
    if ($message !== '') {
      $activity_data[] = array('message' => $message, 'time' => $item->getTs());
    }
  }
  $announcement_data = array();
  foreach (array_slice($announcements, 0, 6) as $announcement) {
    $announcement_data[] = $announcement->getAnnouncement();
  }
  musicTvJson(array(
    'active_tv' => $active_tv,
    'playback' => $playback->toArray(),
    'queue' => array_map($row ==> $row->toArray(), $queue),
    'stage' => $stage->toArray(),
    'leaderboard' => $leaderboard,
    'activity' => $activity_data,
    'announcements' => $announcement_data,
  ));
}

/* HH_IGNORE_ERROR[1002] */
\HH\Asio\join(genMusicTvData());
