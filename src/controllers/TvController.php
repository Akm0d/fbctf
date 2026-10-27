<?hh // strict

class TvController extends Controller {
  <<__Override>>
  protected function getTitle(): string {
    return 'FBCTF Party TV';
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
    return
      <main class="tv-page">
        <header class="tv-header">
          <div>
            <h1>FBCTF Party Live</h1>
            <p class="tv-player-status">Waiting for the music queue…</p>
          </div>
          <button type="button" class="fb-cta cta--yellow tv-enable-audio">Start TV Audio</button>
        </header>
        <div class="tv-layout">
          <section class="tv-player-panel">
            <div id="youtube-player"></div>
            <div class="tv-now-playing">
              <div>
                <span>Now playing</span>
                <h2 class="tv-song-title">Nothing queued</h2>
                <p class="tv-request-team"></p>
              </div>
              <a class="tv-youtube-link" target="_blank" rel="noopener">Open on YouTube</a>
            </div>
          </section>
          <aside class="tv-sidebar">
            <section class="tv-card tv-stage">
              <h2>Karaoke Stage</h2>
              <strong class="tv-stage-owner">Unclaimed</strong>
              <span class="tv-stage-rate"></span>
            </section>
            <section class="tv-card">
              <h2>Leaderboard</h2>
              <ol class="tv-leaderboard"></ol>
            </section>
          </aside>
          <section class="tv-card tv-upcoming-card">
            <h2>Up Next</h2>
            <div class="tv-upcoming"></div>
          </section>
          <div class="tv-lower-side">
            <section class="tv-card">
              <h2>Recent Activity</h2>
              <div class="tv-activity"></div>
            </section>
            <section class="tv-card tv-announcements-card">
              <h2>Announcements</h2>
              <div class="tv-announcements"></div>
            </section>
          </div>
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
      <body data-section="tv">
        <div id="fb-main-content">{$content}</div>
        <script type="text/javascript" src="static/dist/js/app.js"></script>
        <script type="text/javascript" src="https://www.youtube.com/iframe_api"></script>
      </body>;
  }
}
