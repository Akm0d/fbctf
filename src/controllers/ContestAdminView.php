<?hh // strict

class ContestAdminView {
  private static function categorySelect(
    array<Category> $categories,
    int $selected,
  ): :xhp {
    $select = <select name="category_id" required={true}><option value="">Choose category</option></select>;
    foreach ($categories as $category) {
      $select->appendChild(
        <option
          value={strval($category->getId())}
          selected={$category->getId() === $selected}>
          {$category->getCategory()}
        </option>,
      );
    }
    return $select;
  }

  private static function countrySelect(
    array<Country> $countries,
    int $selected,
  ): :xhp {
    $select = <select name="entity_id" required={true}><option value="">Choose country</option></select>;
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

  private static function contestForm(
    ?Map<string, string> $contest,
    array<Category> $categories,
    array<Country> $countries,
  ): :xhp {
    $is_new = $contest === null;
    $id = $is_new ? 0 : intval(must_have_idx($contest, 'id'));
    $title = $is_new ? '' : must_have_idx($contest, 'title');
    $description = $is_new ? '' : must_have_idx($contest, 'description');
    $entity_id = $is_new ? 0 : intval(idx($contest, 'entity_id', 0));
    $category_id = $is_new ? 0 : intval(idx($contest, 'category_id', 0));
    $submission_type = $is_new ? 'text' : must_have_idx($contest, 'submission_type');
    $vote_points = $is_new ? '10' : must_have_idx($contest, 'vote_points');
    $winner_points = $is_new ? '100' : must_have_idx($contest, 'winner_points');
    $visible = !$is_new && boolval(must_have_idx($contest, 'visible'));
    $winner_awarded = !$is_new && boolval(must_have_idx($contest, 'finalized'));

    return
      <form class="contest-admin-form admin-box">
        <input
          type="hidden"
          name="action"
          value={$is_new ? 'create_contest' : 'update_contest'}
        />
        <input type="hidden" name="contest_id" value={strval($id)} />
        <header class="admin-box-header">
          <h3>{$is_new ? 'Create Contest' : 'Contest #'.strval($id)}</h3>
          <span>{$winner_awarded ? 'Winner points awarded' : 'Follows game status'}</span>
        </header>
        <div class="contest-admin-grid">
          <div class="form-el el--block-label el--full-text">
            <label>Title</label>
            <input type="text" name="title" maxlength={200} value={$title} required={true} />
          </div>
          <div class="form-el el--block-label el--full-text">
            <label>Country</label>
            {self::countrySelect($countries, $entity_id)}
          </div>
          <div class="form-el el--block-label el--full-text">
            <label>Category</label>
            {self::categorySelect($categories, $category_id)}
          </div>
          <div class="form-el el--block-label el--full-text contest-span-full">
            <label>Description</label>
            <textarea name="description" rows={4} required={true}>{$description}</textarea>
          </div>
          <div class="form-el el--block-label el--full-text">
            <label>Submission type</label>
            <select name="submission_type">
              <option value="text" selected={$submission_type === 'text'}>Text</option>
              <option value="image" selected={$submission_type === 'image'}>Image</option>
            </select>
          </div>
          <div class="form-el el--block-label el--full-text">
            <label>Points per voter</label>
            <input type="number" min="0" name="vote_points" value={$vote_points} required={true} />
          </div>
          <div class="form-el el--block-label el--full-text">
            <label>Winner points</label>
            <input type="number" min="0" name="winner_points" value={$winner_points} required={true} />
          </div>
          <p class="contest-help contest-span-full">
            Submissions and voting open when the game starts and close when it ends.
          </p>
          <label class="contest-check">
            <input type="checkbox" name="visible" value="1" checked={$visible} /> Visible
          </label>
        </div>
        <div class="admin-buttons admin-row">
          <button class="fb-cta cta--yellow" type="submit">
            {$is_new ? 'Create contest' : 'Save contest'}
          </button>
        </div>
      </form>;
  }

  private static async function genContestAdmin(
    Map<string, string> $contest,
    array<Category> $categories,
    array<Country> $countries,
  ): Awaitable<:xhp> {
    $contest_id = intval(must_have_idx($contest, 'id'));
    $entries = await Contest::genEntries($contest_id);
    $rankings = <div class="contest-admin-rankings"></div>;
    $winner_options = <select name="entry_id" required={true}><option value="">Choose winning team</option></select>;
    $rank = 1;
    foreach ($entries as $entry) {
      $rankings->appendChild(
        <div class="contest-ranking-row">
          <span>#{strval($rank)} {must_have_idx($entry, 'team_name')}</span>
          <strong>{must_have_idx($entry, 'vote_count')} votes</strong>
        </div>,
      );
      $winner_options->appendChild(
        <option value={must_have_idx($entry, 'id')}>
          {must_have_idx($entry, 'team_name')} ({must_have_idx($entry, 'vote_count')} votes)
        </option>,
      );
      $rank++;
    }
    if (count($entries) === 0) {
      $rankings->appendChild(<p>No entries yet.</p>);
    }
    $winner_award = boolval(must_have_idx($contest, 'finalized'))
      ? <p class="contest-winner">Winner: {must_have_idx($contest, 'winning_team_name')}</p>
      : <form class="contest-admin-form contest-finalize-form">
          <input type="hidden" name="action" value="finalize_contest" />
          <input type="hidden" name="contest_id" value={strval($contest_id)} />
          {$winner_options}
          <p class="contest-help">Ties are shown above; choose the winner explicitly.</p>
          <button type="submit" class="fb-cta cta--yellow" disabled={count($entries) === 0}>
            Award winner points once
          </button>
        </form>;
    $delete =
      <form class="contest-admin-form contest-delete-form admin-buttons admin-row">
        <input type="hidden" name="action" value="delete_contest" />
        <input type="hidden" name="contest_id" value={strval($contest_id)} />
        <button type="submit" class="fb-cta cta--red">Delete contest</button>
      </form>;
    return
      <div>
        {self::contestForm($contest, $categories, $countries)}
        {$delete}
        <section class="admin-box">
          <header class="admin-box-header"><h3>Vote rankings</h3></header>
          {$rankings}
          {$winner_award}
        </section>
      </div>;
  }

  public static async function genRender(): Awaitable<:xhp> {
    list($categories, $countries) = await \HH\Asio\va(
      Category::genAllCategories(),
      Country::genAllCountries(),
    );
    $contests = await Contest::genAll();
    $content = <div class="admin-sections contest-admin-page"></div>;
    $content->appendChild(self::contestForm(null, $categories, $countries));
    foreach ($contests as $contest) {
      $contest_admin = await self::genContestAdmin($contest, $categories, $countries);
      $content->appendChild($contest_admin);
    }
    return
      <div>
        <header class="admin-page-header">
          <h3>Contest Management</h3>
          <span class="admin-section--status">status_ <span class="highlighted">OK</span></span>
        </header>
        <p class="contest-help">
          Voting allows one vote per browser session, capped by recorded team members.
          Teams without member records use Configuration &gt; Registration &gt; Players Per Team.
        </p>
        {$content}
      </div>;
  }
}
