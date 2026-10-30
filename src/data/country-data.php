<?hh // strict

require_once ($_SERVER['DOCUMENT_ROOT'].'/../vendor/autoload.php');

class CountryDataController extends DataController {
  private async function genOwnership(
    Level $level,
    array<string> $completed_by,
  ): Awaitable<(string, array<string>)> {
    if ($level->getType() === 'base') {
      $manual_owner = await ManualBase::genOwnerName($level->getId());
      if ($manual_owner !== null) {
        return tuple(
          $manual_owner === '' ? 'Uncaptured' : $manual_owner,
          $manual_owner === '' ? array() : array($manual_owner),
        );
      }
    }
    if ($completed_by) {
      $team = await MultiTeam::genFirstCapture($level->getId());
      return tuple($team->getName(), $completed_by);
    }
    return tuple('Uncaptured', $completed_by);
  }

  private function lockedCountryData(Category $category): stdClass {
    return (object) array(
      'locked' => true,
      'category' => $category->getCategory(),
      'title' => '🔒 Locked',
    );
  }

  private async function genUnlockedCountryData(
    Level $level,
    Category $category,
    Team $my_team,
  ): Awaitable<stdClass> {
    $awaitables = Map {
      'attachments_list' => Attachment::genAllAttachmentsFileNamesLinks(
        $level->getId(),
      ),
      'links_list' => Link::genAllLinksValues($level->getId()),
      'completed_by' => MultiTeam::genCompletedLevelTeamNames(
        $level->getId(),
      ),
    };
    $results = await \HH\Asio\m($awaitables);

    if ($level->getHint() === '') {
      $hint_cost = -1;
      $hint = 'no';
    } else if ($level->getPenalty() > $my_team->getPoints()) {
      $hint_cost = -2;
      $hint = 'no';
    } else {
      list($previous_hint, $previous_score) = await \HH\Asio\va(
        HintLog::genPreviousHint($level->getId(), $my_team->getId(), false),
        ScoreLog::genPreviousScore($level->getId(), $my_team->getId(), false),
      );
      $hint_cost = ($previous_hint || $previous_score)
        ? 0
        : $level->getPenalty();
      $hint = $hint_cost === 0 ? $level->getHint() : 'yes';
    }

    list($owner, $completed_by) =
      await $this->genOwnership($level, $results['completed_by']);
    $team_completed = await ScoreLog::genAllPreviousScore(
      $level->getId(),
      $my_team->getId(),
      false,
    );
    $next_destinations = array();
    if ($team_completed) {
      $next_destinations =
        await Level::genDependentCountryNames($level->getId());
    }
    $migi_vault = $level->getType() === 'base' &&
      strcasecmp($category->getCategory(), 'Migi') === 0;

    return (object) array(
      'locked' => false,
      'level_id' => $level->getId(),
      'title' => $level->getTitle(),
      'intro' => $level->getDescription(),
      'type' => $level->getType(),
      'points' => $level->getPoints(),
      'bonus' => $level->getBonus(),
      'category' => $category->getCategory(),
      'owner' => $owner,
      'completed' => $completed_by,
      'hint' => $hint,
      'hint_cost' => $hint_cost,
      'attachments' => $results['attachments_list'],
      'links' => $results['links_list'],
      'next_destinations' => $next_destinations,
      'completion_message' => $team_completed && count($next_destinations) === 0
        ? $level->getCompletionMessage()
        : null,
      'migi_chat' => MigiChat::enabled() && $migi_vault,
      'migi_vault' => $migi_vault,
    );
  }

  public async function genGenerateData(): Awaitable<void> {

    /* HH_IGNORE_ERROR[1002] */
    SessionUtils::sessionStart();
    SessionUtils::enforceLogin();

    list($my_team, $gameboard, $all_active_levels) = await \HH\Asio\va(
      MultiTeam::genTeam(SessionUtils::sessionTeam()),
      Configuration::gen('gameboard'),
      Level::genAllActiveLevels(),
    );

    $countries_data = (object) array();

    // If gameboard refresing is disabled, exit
    if ($gameboard->getValue() === '0') {
      $this->jsonSend($countries_data);
      exit(1);
    }

    foreach ($all_active_levels as $level) {
      list($country, $category, $unlocked) = await \HH\Asio\va(
        Country::gen(intval($level->getEntityId())),
        Category::genSingleCategory($level->getCategoryId()),
        Level::genPrerequisiteSatisfied($level->getId(), $my_team->getId()),
      );
      if (!$unlocked) {
        $countries_data->{$country->getName()} =
          $this->lockedCountryData($category);
        continue;
      }
      /* HH_FIXME[1002] */
      /* HH_FIXME[2011] */
      $countries_data->{$country->getName()} =
        await $this->genUnlockedCountryData($level, $category, $my_team);
    }

    $this->jsonSend($countries_data);
  }
}

$countryData = new CountryDataController();
$countryData->sendData();
