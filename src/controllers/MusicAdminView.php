<?hh // strict

class MusicAdminView {
  private static function actionForm(
    string $action,
    string $label,
    array<string, string> $values,
    string $class = 'cta--blue',
  ): :xhp {
    $form = <form class="music-admin-form music-inline-form"><input type="hidden" name="action" value={$action} /></form>;
    foreach ($values as $name => $value) {
      $form->appendChild(<input type="hidden" name={$name} value={$value} />);
    }
    $form->appendChild(<button type="submit" class={'fb-cta '.$class}>{$label}</button>);
    return $form;
  }

  private static function countrySelect(
    string $name,
    array<Country> $countries,
    int $selected,
  ): :xhp {
    $select = <select name={$name}><option value="0">Configure later</option></select>;
    foreach ($countries as $country) {
      if (!$country->getEnabled()) {
        continue;
      }
      $select->appendChild(
        <option
          value={strval($country->getId())}
          selected={$country->getId() === $selected}
          disabled={$country->getUsed() && $country->getId() !== $selected}>
          {$country->getName()}
        </option>,
      );
    }
    return $select;
  }

  public static async function genRender(): Awaitable<:xhp> {
    list($requests, $queue, $performances, $playback, $stage, $countries, $categories) = await \HH\Asio\va(
      Music::genRequests(),
      Music::genQueue(),
      Music::genUnconfirmedPerformances(),
      Music::genPlayback(),
      Music::genStage(),
      Country::genAllCountries(),
      Category::genAllCategories(),
    );
    $activity_country = 0;
    $stage_country = 0;
    $category_id = 0;
    $activity_level_id = intval(idx($stage, 'activity_level_id', 0));
    $stage_level_id = intval(idx($stage, 'stage_level_id', 0));
    if ($activity_level_id > 0) {
      $level = await Level::gen($activity_level_id);
      $activity_country = $level->getEntityId();
      $category_id = $level->getCategoryId();
    }
    if ($stage_level_id > 0) {
      $level = await Level::gen($stage_level_id);
      $stage_country = $level->getEntityId();
      if ($category_id === 0) {
        $category_id = $level->getCategoryId();
      }
    }

    $request_rows = <div class="music-list"></div>;
    foreach ($requests as $request) {
      $id = must_have_idx($request, 'id');
      $status = must_have_idx($request, 'status');
      $request_rows->appendChild(
        <div class="music-admin-row">
          <div>
            <strong>{must_have_idx($request, 'display_title')}</strong>
            <span>{must_have_idx($request, 'team_name')} · {must_have_idx($request, 'request_type')} · {$status}</span>
            <a target="_blank" rel="noopener" href={'https://youtu.be/'.must_have_idx($request, 'youtube_id')}>Open on YouTube</a>
          </div>
          <div class="music-actions">
            {self::actionForm('move_request', '↑', array('request_id' => $id, 'direction' => 'up'))}
            {self::actionForm('move_request', '↓', array('request_id' => $id, 'direction' => 'down'))}
            {self::actionForm('moderate_request', 'Approve', array('request_id' => $id, 'status' => 'approved'))}
            {self::actionForm('moderate_request', 'Reject', array('request_id' => $id, 'status' => 'rejected'), 'cta--red')}
            {self::actionForm('schedule_request', 'Queue end', array('request_id' => $id, 'mode' => 'end'))}
            {self::actionForm('schedule_request', 'Play next', array('request_id' => $id, 'mode' => 'next'))}
            {self::actionForm('schedule_request', 'Play now', array('request_id' => $id, 'mode' => 'now'), 'cta--yellow')}
            {self::actionForm('moderate_request', 'Remove', array('request_id' => $id, 'status' => 'removed'), 'cta--red')}
          </div>
        </div>,
      );
    }
    if (count($requests) === 0) {
      $request_rows->appendChild(<p>No guest requests.</p>);
    }

    $queue_rows = <div class="music-list"></div>;
    foreach ($queue as $item) {
      $id = must_have_idx($item, 'id');
      $karaoke = boolval(must_have_idx($item, 'is_karaoke'));
      $queue_rows->appendChild(
        <div class={'music-admin-row '.(must_have_idx($item, 'status') === 'playing' ? 'is-playing' : '')}>
          <div>
            <strong>{must_have_idx($item, 'display_title')}</strong>
            <span>
              {must_have_idx($item, 'status')} ·
              {$karaoke ? 'karaoke for '.must_have_idx($item, 'team_name') : 'party playlist'}
            </span>
            <a target="_blank" rel="noopener" href={'https://youtu.be/'.must_have_idx($item, 'youtube_id')}>Open on YouTube</a>
          </div>
          <div class="music-actions">
            {self::actionForm('move_queue', '↑', array('queue_id' => $id, 'direction' => 'up'))}
            {self::actionForm('move_queue', '↓', array('queue_id' => $id, 'direction' => 'down'))}
            {self::actionForm('play_queue', 'Play now', array('queue_id' => $id), 'cta--yellow')}
            {$karaoke && must_have_idx($item, 'status') === 'playing' ? self::actionForm('confirm_performance', 'Mark Performance Complete', array('queue_id' => $id), 'cta--yellow') : <span></span>}
            {self::actionForm('remove_queue', 'Remove', array('queue_id' => $id), 'cta--red')}
          </div>
        </div>,
      );
    }
    if (count($queue) === 0) {
      $queue_rows->appendChild(<p>The playing queue is empty.</p>);
    }

    $performance_rows = <div class="music-list"></div>;
    foreach ($performances as $item) {
      $performance_rows->appendChild(
        <div class="music-admin-row">
          <div>
            <strong>{must_have_idx($item, 'display_title')}</strong>
            <span>{must_have_idx($item, 'team_name')} · {must_have_idx($item, 'status')}</span>
          </div>
          <div class="music-actions">
            {self::actionForm(
              'confirm_performance',
              'Mark Performance Complete',
              array('queue_id' => must_have_idx($item, 'id')),
              'cta--yellow',
            )}
          </div>
        </div>,
      );
    }
    if (count($performances) === 0) {
      $performance_rows->appendChild(<p>No recently finished karaoke performances await confirmation.</p>);
    }

    $category_select = <select name="category_id"><option value="0">Choose category</option></select>;
    foreach ($categories as $category) {
      $category_select->appendChild(
        <option value={strval($category->getId())} selected={$category->getId() === $category_id}>
          {$category->getCategory()}
        </option>,
      );
    }

    return
      <div class="music-admin-page">
        <header class="admin-page-header">
          <h3>Party Music &amp; Karaoke</h3>
          <a href="/tv" target="_blank" rel="noopener">Open TV display</a>
        </header>
        <section class="admin-box music-admin-section">
          <header class="admin-box-header"><h3>Current playback</h3></header>
          <p class="music-now-title">{strval(idx($playback, 'display_title', '')) === '' ? 'Nothing playing' : must_have_idx($playback, 'display_title')}</p>
          <p>TV command: {must_have_idx($playback, 'desired_state')} · version {must_have_idx($playback, 'command_version')}</p>
          <div class="music-actions">
            {self::actionForm('playback', 'Play', array('command' => 'play'), 'cta--yellow')}
            {self::actionForm('playback', 'Pause', array('command' => 'pause'))}
            {self::actionForm('playback', 'Skip', array('command' => 'skip'), 'cta--red')}
          </div>
        </section>
        <section class="admin-box music-admin-section">
          <header class="admin-box-header"><h3>Preload party playlist</h3></header>
          <form class="music-admin-form music-stack-form">
            <input type="hidden" name="action" value="add_playlist" />
            <p>One URL per line. Optional title format: <code>Display title | YouTube URL</code>.</p>
            <textarea name="playlist_text" rows={8} required={true}></textarea>
            <button type="submit" class="fb-cta cta--yellow">Add songs</button>
          </form>
        </section>
        <section class="admin-box music-admin-section">
          <header class="admin-box-header"><h3>Playing queue</h3></header>
          <p>Played songs stay in this list. Restarting resets the party songs and begins from the first one; guest requests remain scheduled separately.</p>
          <div class="music-actions">
            {self::actionForm('replay_playlist', 'Restart party playlist', array(), 'cta--yellow')}
          </div>
          {$queue_rows}
        </section>
        <section class="admin-box music-admin-section">
          <header class="admin-box-header"><h3>Karaoke performance confirmation</h3></header>
          {$performance_rows}
        </section>
        <section class="admin-box music-admin-section">
          <header class="admin-box-header"><h3>Guest requests</h3></header>
          {$request_rows}
        </section>
        <section class="admin-box music-admin-section">
          <header class="admin-box-header"><h3>Karaoke Stage configuration</h3></header>
          <p>Owner: {strval(idx($stage, 'owner_team_name', '')) === '' ? 'Unclaimed' : must_have_idx($stage, 'owner_team_name')}</p>
          <form class="music-admin-form music-stack-form">
            <input type="hidden" name="action" value="configure_karaoke" />
            <label>Points per completed performance</label>
            <input type="number" min="0" name="performance_points" value={must_have_idx($stage, 'performance_points')} required={true} />
            <label>Passive stage points per minute</label>
            <input type="number" min="0" name="passive_points" value={must_have_idx($stage, 'passive_points_per_minute')} required={true} />
            <label>Activity country</label>
            {self::countrySelect('activity_country', $countries, $activity_country)}
            <label>Stage country</label>
            {self::countrySelect('stage_country', $countries, $stage_country)}
            <label>Category</label>
            {$category_select}
            <label class="contest-check">
              <input type="checkbox" name="enabled" value="1" checked={boolval(must_have_idx($stage, 'enabled'))} /> Enabled
            </label>
            <button type="submit" class="fb-cta cta--yellow">Save Karaoke configuration</button>
          </form>
        </section>
      </div>;
  }
}
