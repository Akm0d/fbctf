var $ = require('jquery');

function submit(form, endpoint) {
  var data = new FormData(form);
  data.append('csrf_token', $('input[name="csrf_token"]').first().val());
  var $button = $('button[type="submit"]', form).prop('disabled', true);
  $.ajax({
    url: endpoint,
    method: 'POST',
    data: data,
    processData: false,
    contentType: false
  }).done(function(raw) {
    var response = typeof raw === 'string' ? JSON.parse(raw) : raw;
    window.alert(response.message);
    if (response.result === 'OK') {
      window.location.reload();
    }
  }).fail(function() {
    window.alert('The music request failed. Please try again.');
  }).always(function() {
    $button.prop('disabled', false);
  });
}

module.exports = {
  initGuest: function() {
    $(document).on('submit', '.music-ajax-form', function(event) {
      event.preventDefault();
      submit(this, 'index.php?p=music&ajax=true');
    });
  },
  initAdmin: function() {
    $(document).on('submit', '.music-admin-form', function(event) {
      event.preventDefault();
      submit(this, 'index.php?p=music-admin&ajax=true');
    });
  }
};
