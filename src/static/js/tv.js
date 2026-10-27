var $ = require('jquery');

var player = null;
var ready = false;
var audioEnabled = false;
var currentQueueId = 0;
var currentVideoId = '';
var pendingVideoId = '';
var appliedVersion = -1;
var clientKey = window.localStorage.getItem('fbctf-tv-key');

if (!clientKey) {
  var bytes = new Uint8Array(24);
  window.crypto.getRandomValues(bytes);
  clientKey = Array.prototype.map.call(bytes, function(value) {
    return value.toString(16).padStart(2, '0');
  }).join('');
  window.localStorage.setItem('fbctf-tv-key', clientKey);
}

function escapeText(value) {
  return $('<div>').text(value || '').html();
}

function reportFinished() {
  if (!currentQueueId) {
    return;
  }
  $.post('/data/music-tv.php', {
    action: 'finished',
    client_key: clientKey,
    queue_id: currentQueueId
  });
}

function createPlayer() {
  if (player || !pendingVideoId || !window.YT || !window.YT.Player) {
    return;
  }
  currentVideoId = pendingVideoId;
  player = new window.YT.Player('youtube-player', {
    width: '100%',
    height: '100%',
    videoId: currentVideoId,
    playerVars: {autoplay: 0, controls: 1, rel: 0, playsinline: 1},
    events: {
      onReady: function() {
        ready = true;
        $('.tv-player-status').text('TV player ready');
      },
      onStateChange: function(event) {
        if (event.data === window.YT.PlayerState.ENDED) {
          reportFinished();
        }
      },
      onError: function() {
        $('.tv-player-status').text('This video cannot be embedded. Open it on YouTube or skip it from Admin.');
      }
    }
  });
}

window.onYouTubeIframeAPIReady = function() {
  createPlayer();
};

function renderList(selector, values, formatter) {
  $(selector).html(values.map(formatter).join(''));
}

function applyData(data) {
  var playback = data.playback || {};
  var queue = data.queue || [];
  var stage = data.stage || {};
  var version = parseInt(playback.command_version || 0, 10);
  var queueId = parseInt(playback.current_queue_id || 0, 10);
  var videoId = playback.youtube_id || '';
  pendingVideoId = videoId;
  createPlayer();

  $('.tv-song-title').text(playback.display_title || 'Nothing queued');
  $('.tv-request-team').text(parseInt(playback.is_karaoke || 0, 10) ? 'Karaoke: ' + (playback.team_name || '') : 'Party playlist');
  $('.tv-youtube-link').attr('href', videoId ? 'https://youtu.be/' + videoId : '#').toggle(!!videoId);
  $('.tv-stage-owner').text(stage.owner_team_name || 'Unclaimed');
  $('.tv-stage-rate').text((stage.passive_points_per_minute || 0) + ' points per minute');
  $('.tv-player-status').text(
    data.active_tv ? 'Active TV player' :
      (audioEnabled ? 'Standby display — another TV controls playback' :
        'Click Start TV Audio to control playback')
  );

  renderList('.tv-leaderboard', data.leaderboard || [], function(team) {
    return '<li><span>' + team.rank + '. ' + escapeText(team.name) + '</span><strong>' + team.points + '</strong></li>';
  });
  renderList('.tv-upcoming', queue.filter(function(item) {
    return item.status === 'queued';
  }).slice(0, 6), function(item) {
    var detail = parseInt(item.is_karaoke || 0, 10) ? 'Karaoke · ' + escapeText(item.team_name) : 'Party song';
    return '<div><strong>' + escapeText(item.display_title) + '</strong><span>' + detail + '</span></div>';
  });
  renderList('.tv-activity', data.activity || [], function(item) {
    return '<p>' + escapeText(item.message) + '</p>';
  });
  renderList('.tv-announcements', data.announcements || [], function(item) {
    return '<p>' + escapeText(item) + '</p>';
  });

  if (!ready) {
    return;
  }
  if (videoId && videoId !== currentVideoId) {
    currentVideoId = videoId;
    player.cueVideoById(videoId);
  }
  if (!data.active_tv || version === appliedVersion) {
    return;
  }
  appliedVersion = version;
  currentQueueId = queueId;
  if (playback.desired_state === 'playing' && audioEnabled) {
    player.playVideo();
  } else {
    player.pauseVideo();
  }
}

function poll() {
  var params = audioEnabled ? {client_key: clientKey} : {};
  $.getJSON('/data/music-tv.php', params)
    .done(applyData)
    .always(function() {
      window.setTimeout(poll, 2000);
    });
}

module.exports = {
  init: function() {
    $('.tv-enable-audio').on('click', function() {
      audioEnabled = true;
      $(this).text('TV Audio Enabled').prop('disabled', true);
      if (player && ready) {
        player.unMute();
        player.playVideo();
      }
    });
    poll();
  }
};
