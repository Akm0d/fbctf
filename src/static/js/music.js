var $ = require('jquery');

var feedbackTimer;
var reorderStates = {
  queue: {running: false, pending: []},
  requests: {running: false, pending: []}
};

function parseResponse(raw) {
  try {
    return typeof raw === 'string' ? JSON.parse(raw) : raw;
  } catch (error) {
    return {result: 'ERROR', message: 'The server returned an invalid response. Please refresh and try again.'};
  }
}

function statusElement() {
  var $status = $('.music-admin-status').first();
  if ($status.length === 0) {
    $status = $('<p/>')
      .addClass('music-admin-status')
      .attr({'role': 'status', 'aria-live': 'polite'})
      .prependTo($('.music-admin-page, .music-shell').first());
  }
  return $status;
}

function showFeedback(message, isError) {
  var $status = statusElement();
  window.clearTimeout(feedbackTimer);
  $status
    .toggleClass('is-error', isError)
    .toggleClass('is-success', !isError)
    .attr('role', isError ? 'alert' : 'status')
    .text(message || (isError ? 'The music request failed. Please try again.' : 'Done'));
  if (!isError) {
    feedbackTimer = window.setTimeout(function() {
      $status.text('').removeClass('is-success');
    }, 3500);
  }
}

function rememberSuccess(message) {
  try {
    window.sessionStorage.setItem('fbctfMusicNotice', message || 'Done');
  } catch (error) {
    window.console.warn('Music success feedback could not be saved', error);
  }
}

function restoreFeedback() {
  try {
    var message = window.sessionStorage.getItem('fbctfMusicNotice');
    if (message) {
      window.sessionStorage.removeItem('fbctfMusicNotice');
      showFeedback(message, false);
    }
  } catch (error) {
    window.console.warn('Music success feedback could not be restored', error);
  }
}

function formData(form) {
  var data = new FormData(form);
  data.append('csrf_token', $('input[name="csrf_token"]').first().val());
  return data;
}

function request(endpoint, data) {
  return $.ajax({
    url: endpoint,
    method: 'POST',
    data: data,
    processData: false,
    contentType: false
  });
}

function refreshList(name) {
  return $.get(window.location.href).then(function(html) {
    var $page = $('<div/>').append($.parseHTML(html));
    var selector = '.music-list[data-music-list="' + name + '"]';
    var $replacement = $(selector, $page).first();
    var $current = $(selector).first();
    if ($replacement.length === 0 || $current.length === 0) {
      return $.Deferred().reject().promise();
    }
    $current.replaceWith($replacement);
  });
}

function finishReorder(name, discardPending) {
  var state = reorderStates[name];
  if (discardPending) {
    state.pending = [];
  }
  state.running = false;
  runNextReorder(name);
}

function reorderError(name, message) {
  showFeedback(message, true);
  finishReorder(name, true);
}

function runNextReorder(name) {
  var state = reorderStates[name];
  if (state.running || state.pending.length === 0) {
    return;
  }
  state.running = true;
  var item = state.pending.shift();
  request(item.endpoint, item.data).done(function(raw) {
    var response = parseResponse(raw);
    if (!response || response.result !== 'OK') {
      reorderError(name, response && response.message ? response.message : 'The song could not be moved.');
      return;
    }
    refreshList(name).done(function() {
      finishReorder(name, false);
    }).fail(function() {
      reorderError(name, 'The order was saved, but the list could not be refreshed. Refresh the page before moving more songs.');
    });
  }).fail(function() {
    reorderError(name, 'The song could not be moved. Check your connection and try again.');
  });
}

function enqueueReorder(form, endpoint, action) {
  var name = action === 'move_queue' ? 'queue' : 'requests';
  reorderStates[name].pending.push({endpoint: endpoint, data: formData(form)});
  runNextReorder(name);
}

function submit(form, endpoint) {
  var $button = $('button[type="submit"]', form).prop('disabled', true);
  request(endpoint, formData(form)).done(function(raw) {
    var response = parseResponse(raw);
    if (!response || response.result !== 'OK') {
      showFeedback(response && response.message ? response.message : 'The music request failed. Please try again.', true);
      return;
    }
    rememberSuccess(response.message);
    window.location.reload();
  }).fail(function() {
    showFeedback('The music request failed. Check your connection and try again.', true);
  }).always(function() {
    $button.prop('disabled', false);
  });
}

module.exports = {
  initGuest: function() {
    restoreFeedback();
    $(document).on('submit', '.music-ajax-form', function(event) {
      event.preventDefault();
      submit(this, 'index.php?p=music&ajax=true');
    });
  },
  initAdmin: function() {
    restoreFeedback();
    $(document).on('submit', '.music-admin-form', function(event) {
      event.preventDefault();
      var action = $('input[name="action"]', this).val();
      if (action === 'move_queue' || action === 'move_request') {
        enqueueReorder(this, 'index.php?p=music-admin&ajax=true', action);
        return;
      }
      submit(this, 'index.php?p=music-admin&ajax=true');
    });
  }
};
