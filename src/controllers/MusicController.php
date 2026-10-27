<?hh // strict

class MusicController extends Controller {
  <<__Override>>
  protected function getTitle(): string {
    return 'FBCTF | Party Music';
  }

  <<__Override>>
  protected function getFilters(): array<string, mixed> {
    return array('GET' => array());
  }

  <<__Override>>
  protected function getPages(): array<string> {
    return array('main');
  }

  public async function genRenderMainContent(): Awaitable<:xhp> {
    list($requests, $stage, $playback, $queue) = await \HH\Asio\va(
      Music::genRequestsForTeam(SessionUtils::sessionTeam()),
      Music::genStage(),
      Music::genPlayback(),
      Music::genQueue(),
    );
    $request_list = <div class="music-list"></div>;
    foreach ($requests as $request) {
      $request_list->appendChild(
        <div class="music-row">
          <div>
            <strong>{must_have_idx($request, 'display_title')}</strong>
            <span>{must_have_idx($request, 'request_type')} · {must_have_idx($request, 'status')}</span>
          </div>
          <a target="_blank" rel="noopener" href={'https://youtu.be/'.must_have_idx($request, 'youtube_id')}>YouTube</a>
        </div>,
      );
    }
    if (count($requests) === 0) {
      $request_list->appendChild(<p>No requests from your team yet.</p>);
    }
    $upcoming = <div class="music-list"></div>;
    $shown = 0;
    foreach ($queue as $item) {
      if (must_have_idx($item, 'status') !== 'queued' || $shown >= 8) {
        continue;
      }
      $team = must_have_idx($item, 'team_name');
      $upcoming->appendChild(
        <div class="music-row">
          <strong>{must_have_idx($item, 'display_title')}</strong>
          <span>{boolval(must_have_idx($item, 'is_karaoke')) ? 'Karaoke · '.$team : 'Party music'}</span>
        </div>,
      );
      $shown++;
    }
    if ($shown === 0) {
      $upcoming->appendChild(<p>The queue is empty.</p>);
    }
    $current_title = strval(idx($playback, 'display_title', ''));
    return
      <main class="music-page fb-scroll">
        <header class="contest-nav">
          <a href="/index.php?p=game">Gameboard</a>
          <h1>Party Music</h1>
          <a href="/index.php?p=logout">Logout</a>
        </header>
        <div class="music-shell">
          <section class="music-card">
            <h2>Now playing</h2>
            <p class="music-now-title">{$current_title === '' ? 'Nothing yet' : $current_title}</p>
            <p>{boolval(idx($playback, 'is_karaoke', false)) ? 'Karaoke for '.strval(idx($playback, 'team_name', '')) : 'Party playlist'}</p>
          </section>
          <section class="music-card">
            <h2>Request a Song</h2>
            <p>Requests go to the hosts for approval. They do not control the TV player.</p>
            <form class="music-ajax-form music-request-form">
              <input type="hidden" name="action" value="request_song" />
              <label>YouTube URL</label>
              <input type="url" name="youtube_url" placeholder="https://www.youtube.com/watch?v=..." required={true} />
              <label>Display title (optional)</label>
              <input type="text" name="display_title" maxlength={255} />
              <label>Request type</label>
              <select name="request_type">
                <option value="regular">Regular song request</option>
                <option value="karaoke">Karaoke performance</option>
              </select>
              <button type="submit" class="fb-cta cta--yellow">Send request</button>
            </form>
          </section>
          <section class="music-card">
            <h2>Your requests</h2>
            {$request_list}
          </section>
          <section class="music-card">
            <h2>Upcoming</h2>
            {$upcoming}
          </section>
          <section class="music-card karaoke-stage-card">
            <h2>Karaoke Stage</h2>
            <p class="music-now-title">
              {strval(idx($stage, 'owner_team_name', '')) === '' ? 'Unclaimed' : must_have_idx($stage, 'owner_team_name')}
            </p>
            <p>{must_have_idx($stage, 'passive_points_per_minute')} points per minute while the game is active.</p>
          </section>
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
      <body data-section="music">
        <input type="hidden" name="csrf_token" value={SessionUtils::CSRFToken()} />
        <div id="fb-main-content" class="fb-page">{$content}</div>
        <script type="text/javascript" src="static/dist/js/app.js"></script>
      </body>;
  }
}
