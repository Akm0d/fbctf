<?hh // strict

class ContestController extends Controller {
  <<__Override>>
  protected function getTitle(): string {
    $custom_org = \HH\Asio\join(Configuration::gen('custom_org'));
    return tr($custom_org->getValue()).' CTF | Contests';
  }

  <<__Override>>
  protected function getFilters(): array<string, mixed> {
    return array(
      'GET' => array('level_id' => FILTER_VALIDATE_INT),
    );
  }

  <<__Override>>
  protected function getPages(): array<string> {
    return array('main');
  }

  private function windowOpen(
    Map<string, string> $contest,
    string $open_field,
    string $close_field,
  ): bool {
    $now = time();
    $open = strval(idx($contest, $open_field, ''));
    $close = strval(idx($contest, $close_field, ''));
    return ($open === '' || strtotime($open) <= $now) &&
      ($close === '' || strtotime($close) > $now);
  }

  private function renderEntry(
    Map<string, string> $entry,
    Map<string, string> $contest,
    ?Map<string, string> $current_vote,
    bool $voting_open,
    bool $show_results,
  ): :xhp {
    $entry_id = intval(must_have_idx($entry, 'id'));
    $entry_team_id = intval(must_have_idx($entry, 'team_id'));
    $is_own = $entry_team_id === SessionUtils::sessionTeam();
    $is_current = $current_vote !== null &&
      intval(must_have_idx($current_vote, 'entry_id')) === $entry_id;
    $content = <div></div>;
    if (must_have_idx($contest, 'submission_type') === 'image') {
      $content =
        <img
          class="contest-entry-image"
          src={'/data/contest-image.php?id='.strval($entry_id)}
          alt={'Contest entry from '.must_have_idx($entry, 'team_name')}
        />;
    } else {
      $content = <p class="contest-entry-text">{strval(idx($entry, 'text_content', ''))}</p>;
    }

    $vote_control = <div></div>;
    if ($voting_open && !$is_own) {
      $vote_control =
        <form class="contest-ajax-form contest-vote-form">
          <input type="hidden" name="action" value="vote" />
          <input type="hidden" name="contest_id" value={must_have_idx($contest, 'id')} />
          <input type="hidden" name="entry_id" value={strval($entry_id)} />
          <button type="submit" class={'fb-cta '.($is_current ? 'cta--blue' : 'cta--yellow')}>
            {$is_current ? 'Your vote' : 'Vote for this entry'}
          </button>
        </form>;
    } else if ($voting_open && $is_own) {
      $vote_control = <p class="contest-help">You cannot vote for your own team.</p>;
    }

    $results = $show_results
      ? <p class="contest-vote-total">{must_have_idx($entry, 'vote_count')} votes</p>
      : <p></p>;
    return
      <article class={'contest-entry-card'.($is_current ? ' current-vote' : '')}>
        <header><h3>{must_have_idx($entry, 'team_name')}</h3>{$results}</header>
        {$content}
        {$vote_control}
      </article>;
  }

  private async function genRenderContest(
    Map<string, string> $contest,
    bool $game_running,
  ): Awaitable<:xhp> {
    $contest_id = intval(must_have_idx($contest, 'id'));
    $active = boolval(must_have_idx($contest, 'active'));
    $finalized = boolval(must_have_idx($contest, 'finalized'));
    $submission_open = $active && !$finalized && $game_running;
    $voting_open = $active && !$finalized && $this->windowOpen(
      $contest,
      'voting_open_ts',
      'voting_close_ts',
    );
    $entries = await Contest::genEntries($contest_id);
    $own_entry = await Contest::genEntryForTeam($contest_id, SessionUtils::sessionTeam());
    $current_vote = null;
    $current_vote = await Contest::genVoteForSession(
      $contest_id,
      hash('sha256', session_id()),
    );

    $entry_form = <div></div>;
    if ($submission_open) {
      if (must_have_idx($contest, 'submission_type') === 'image') {
        $entry_input =
          <div>
            <label>Photo entry</label>
            <input
              class="contest-image-input"
              type="file"
              name="contest_image"
              accept="image/jpeg,image/png,image/gif,image/webp"
              required={true}
            />
            <img class="contest-image-preview" src="" alt="Image preview" />
          </div>;
      } else {
        $existing_text = $own_entry === null ? '' : strval(idx($own_entry, 'text_content', ''));
        $entry_input =
          <div>
            <label>Team entry</label>
            <textarea name="text_content" maxlength={5000} rows={4} required={true}>
              {$existing_text}
            </textarea>
          </div>;
      }
      $entry_form =
        <form class="contest-ajax-form contest-entry-form" enctype="multipart/form-data">
          <input type="hidden" name="action" value="submit_entry" />
          <input type="hidden" name="contest_id" value={strval($contest_id)} />
          <input
            type="hidden"
            name="submission_type"
            value={must_have_idx($contest, 'submission_type')}
          />
          {$entry_input}
          <button type="submit" class="fb-cta cta--yellow">
            {$own_entry === null ? 'Submit team entry' : 'Update team entry'}
          </button>
        </form>;
    }

    $entry_cards = <div class="contest-entry-grid"></div>;
    foreach ($entries as $entry) {
      $entry_cards->appendChild(
        $this->renderEntry(
          $entry,
          $contest,
          $current_vote,
          $voting_open,
          !$voting_open || $finalized,
        ),
      );
    }
    if (count($entries) === 0) {
      $entry_cards->appendChild(<p>No entries yet.</p>);
    }

    $winner = $finalized
      ? <p class="contest-winner">Winner: {must_have_idx($contest, 'winning_team_name')}</p>
      : <p></p>;
    return
      <section class="contest-panel contest-card">
        <header class="contest-card-header">
          <div>
            <p class="contest-category">{must_have_idx($contest, 'category')}</p>
            <h2>{must_have_idx($contest, 'title')}</h2>
            <p class="contest-country">{must_have_idx($contest, 'country_name')}</p>
          </div>
          <span class={'contest-status '.($active ? 'is-open' : 'is-closed')}>
            {$finalized ? 'Finalized' : ($active ? 'Open' : 'Closed')}
          </span>
        </header>
        <p>{must_have_idx($contest, 'description')}</p>
        <p class="contest-rewards">
          One vote per phone/browser session (maximum two per team) ·
          Vote reward: {must_have_idx($contest, 'vote_points')} points ·
          Winner reward: {must_have_idx($contest, 'winner_points')} points
        </p>
        <p class="contest-help">Team submissions stay open while the game is running and close when it ends.</p>
        {$winner}
        {$entry_form}
        <h3>Entries</h3>
        {$entry_cards}
      </section>;
  }

  public async function genRenderMainContent(): Awaitable<:xhp> {
    $level_id = intval(idx(Utils::getGET(), 'level_id', 0));
    $contests = await Contest::genAll(true, $level_id);
    $game_running = await Contest::genGameRunning();
    $contest_list = <div></div>;
    foreach ($contests as $contest) {
      $rendered_contest = await $this->genRenderContest($contest, $game_running);
      $contest_list->appendChild($rendered_contest);
    }
    if (count($contests) === 0) {
      $contest_list->appendChild(
        <section class="contest-panel"><p>No contests are visible yet.</p></section>,
      );
    }
    return
      <main class="contest-page fb-scroll">
        <header class="contest-nav">
          <a href="/index.php?p=game">Gameboard</a>
          <h1>Team Contests</h1>
          <a href="/index.php?p=logout">Logout</a>
        </header>
        <div class="contest-shell">
          <p class="contest-team-name">Team: {SessionUtils::sessionTeamName()}</p>
          {$contest_list}
        </div>
      </main>;
  }

  public async function genRenderPage(string $_): Awaitable<:xhp> {
    return await $this->genRenderMainContent();
  }

  <<__Override>>
  public async function genRenderBody(string $page): Awaitable<:xhp> {
    $content = await $this->genRenderPage($page);
    return
      <body data-section="contests">
        <input type="hidden" name="csrf_token" value={SessionUtils::CSRFToken()} />
        <div id="fb-main-content" class="fb-page">{$content}</div>
        <script type="text/javascript" src="static/dist/js/app.js"></script>
      </body>;
  }
}
