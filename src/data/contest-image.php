<?hh // strict

require_once ($_SERVER['DOCUMENT_ROOT'].'/../vendor/autoload.php');

/* HH_IGNORE_ERROR[1002] */
SessionUtils::sessionStart();
SessionUtils::enforceLogin();

$entry_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!is_int($entry_id) || $entry_id < 1) {
  http_response_code(404);
  exit;
}

async function genContestImage(int $entry_id): Awaitable<void> {
  $contest = await ContestImageData::gen($entry_id);
  if ($contest === null) {
    http_response_code(404);
    return;
  }
  $filename = must_have_idx($contest, 'image_filename');
  if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|gif|webp)$/', $filename)) {
    http_response_code(404);
    return;
  }
  $path = Contest::IMAGE_DIR.$filename;
  if (!is_file($path)) {
    http_response_code(404);
    return;
  }
  $image = getimagesize($path);
  if ($image === false) {
    http_response_code(404);
    return;
  }
  header('Content-Type: '.strval(idx($image, 'mime', 'application/octet-stream')));
  header('Content-Length: '.strval(filesize($path)));
  header('Content-Disposition: inline; filename="contest-image"');
  header('X-Content-Type-Options: nosniff');
  readfile($path);
}

class ContestImageData extends Model {
  public static async function gen(
    int $entry_id,
  ): Awaitable<?Map<string, string>> {
    $db = await self::genDb();
    $result = await $db->queryf(
      'SELECT e.image_filename FROM contest_entries e INNER JOIN contests c ON c.id = e.contest_id WHERE e.id = %d AND c.visible = 1 AND e.image_filename IS NOT NULL LIMIT 1',
      $entry_id,
    );
    return $result->numRows() === 1 ? $result->mapRows()[0] : null;
  }
}

/* HH_IGNORE_ERROR[1002] */
\HH\Asio\join(genContestImage($entry_id));
