var $ = require('jquery');

function submitForm(form, endpoint) {
  var $form = $(form);
  var data = new FormData(form);
  data.append('csrf_token', $('input[name="csrf_token"]').first().val());
  var $button = $('button[type="submit"]', $form).prop('disabled', true);
  return $.ajax({
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
    window.alert('The request failed. Please try again.');
  }).always(function() {
    $button.prop('disabled', false);
  });
}

function enableImagePreviews() {
  $('.contest-image-input').attr('capture', 'environment');
  $(document).on('change', '.contest-image-input', function() {
    var preview = $(this).siblings('.contest-image-preview')[0];
    var file = this.files && this.files[0];
    if (!preview || !file) {
      return;
    }
    preview.src = URL.createObjectURL(file);
    preview.classList.add('has-image');
  });
}

module.exports = {
  initPlayer: function() {
    enableImagePreviews();
    $(document).on('submit', '.contest-ajax-form', function(event) {
      event.preventDefault();
      submitForm(this, 'index.php?p=contests&ajax=true');
    });
  },
  initAdmin: function() {
    $(document).on('submit', '.contest-admin-form', function(event) {
      event.preventDefault();
      submitForm(this, 'index.php?p=contest-admin&ajax=true');
    });
  }
};
