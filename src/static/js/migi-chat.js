var $ = require('jquery');

function request($panel, levelId, action, message) {
  return $.ajax({
    url: 'index.php?p=migi&ajax=true',
    method: 'POST',
    data: {
      action: action,
      level_id: levelId,
      message: message || '',
      csrf_token: $('input[name="csrf_token"]').first().val()
    },
    timeout: 70000
  });
}

function render($panel, history) {
  var $messages = $('.migi-chat-messages', $panel).empty();
  $.each(history || [], function() {
    var role = this.role === 'assistant' ? 'migi' : 'player';
    $('<div/>')
      .addClass('migi-message migi-message--' + role)
      .append($('<span/>').addClass('migi-message-author').text(role === 'migi' ? 'Migi' : 'You'))
      .append($('<p/>').text(this.content || ''))
      .appendTo($messages);
  });
  $messages.scrollTop($messages[0] ? $messages[0].scrollHeight : 0);
}

function showError($panel, message) {
  $('.migi-chat-status', $panel).text(message || 'Migi is unavailable right now. Please try again later.');
}

function setBusy($panel, busy) {
  $panel.data('busy', busy);
  $('.js-migi-send, .js-migi-clear', $panel).prop('disabled', busy);
  $('.migi-thinking', $panel).toggleClass('completely-hidden', !busy);
}

module.exports = {
  configure: function($container, enabled, levelId) {
    var $button = $('.js-open-migi-chat', $container);
    var $panel = $('.migi-chat-panel', $container);
    $button.toggleClass('completely-hidden', !enabled);
    $panel.addClass('completely-hidden').data('level-id', levelId).data('busy', false);
    $('.migi-chat-status', $panel).text('');
    $('.migi-chat-input', $panel).val('');
    render($panel, []);
    if (!enabled) {
      return;
    }

    $button.off('click.migi').on('click.migi', function(event) {
      event.preventDefault();
      $panel.removeClass('completely-hidden');
      request($panel, levelId, 'history').done(function(raw) {
        var response = typeof raw === 'string' ? JSON.parse(raw) : raw;
        if (response.result === 'OK') {
          render($panel, response.history);
          $('.migi-chat-input', $panel).focus();
        } else {
          showError($panel, response.message);
        }
      }).fail(function() {
        showError($panel);
      });
    });

    $('.js-migi-send', $panel).off('click.migi').on('click.migi', function(event) {
      event.preventDefault();
      if ($panel.data('busy')) {
        return;
      }
      var $input = $('.migi-chat-input', $panel);
      var message = $.trim($input.val());
      if (!message) {
        return;
      }
      $('.migi-chat-status', $panel).text('');
      setBusy($panel, true);
      request($panel, levelId, 'send', message).done(function(raw) {
        var response = typeof raw === 'string' ? JSON.parse(raw) : raw;
        if (response.result === 'OK') {
          $input.val('');
          render($panel, response.history);
        } else {
          showError($panel, response.message);
        }
      }).fail(function() {
        showError($panel);
      }).always(function() {
        setBusy($panel, false);
        $input.focus();
      });
    });

    $('.migi-chat-input', $panel).off('keydown.migi').on('keydown.migi', function(event) {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        $('.js-migi-send', $panel).trigger('click');
      }
    });

    $('.js-migi-clear', $panel).off('click.migi').on('click.migi', function(event) {
      event.preventDefault();
      if ($panel.data('busy')) {
        return;
      }
      setBusy($panel, true);
      request($panel, levelId, 'clear').done(function(raw) {
        var response = typeof raw === 'string' ? JSON.parse(raw) : raw;
        if (response.result === 'OK') {
          render($panel, response.history);
          $('.migi-chat-status', $panel).text(response.message);
        } else {
          showError($panel, response.message);
        }
      }).fail(function() {
        showError($panel);
      }).always(function() {
        setBusy($panel, false);
      });
    });
  }
};
