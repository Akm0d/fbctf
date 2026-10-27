var BrowserQRCodeReader = require('@zxing/browser').BrowserQRCodeReader;
var $ = require('jquery');

var reader = null;
var controls = null;

function answerInput($scope) {
  return $('input[name="answer"]:enabled', $scope).first();
}

function stopScanner($scope) {
  if (controls) {
    controls.stop();
    controls = null;
  }
  var video = $('video', $scope)[0];
  if (video && video.srcObject) {
    video.srcObject.getTracks().forEach(function(track) {
      track.stop();
    });
    video.srcObject = null;
  }
  $('.qr-scanner', $scope).addClass('completely-hidden');
}

function scanQr($scope) {
  stopScanner($scope);
  var $scanner = $('.qr-scanner', $scope).removeClass('completely-hidden');
  var $status = $('.scan-status', $scanner).text('Starting rear camera…');
  var video = $('video', $scanner)[0];
  video.playsInline = true;
  reader = reader || new BrowserQRCodeReader();
  var constraints = {
    audio: false,
    video: {
      facingMode: {ideal: 'environment'}
    }
  };
  reader.decodeFromConstraints(constraints, video, function(result, error, scannerControls) {
    controls = scannerControls;
    if (result) {
      answerInput($scope).val(result.getText()).trigger('change').focus();
      stopScanner($scope);
    } else if (error && error.name !== 'NotFoundException') {
      $status.text('QR scan error: ' + error.message);
    }
  }).catch(function(error) {
    $status.text('Camera unavailable. Enter the answer manually. ' + error.message);
  });
}

function scanNfc($scope) {
  var $status = $('.scan-status', $scope);
  if (!('NDEFReader' in window)) {
    $status.text('Web NFC is not supported on this device. Enter the answer manually.');
    $('.qr-scanner', $scope).removeClass('completely-hidden');
    return;
  }
  var ndef = new window.NDEFReader();
  ndef.scan().then(function() {
    $('.qr-scanner', $scope).removeClass('completely-hidden');
    $status.text('Hold the phone near the NFC tag…');
    ndef.addEventListener('reading', function(event) {
      var value = '';
      for (var i = 0; i < event.message.records.length; i++) {
        var record = event.message.records[i];
        if (record.recordType === 'text' || record.recordType === 'url' || record.recordType === 'absolute-url') {
          value = new TextDecoder(record.encoding || 'utf-8').decode(record.data);
          break;
        }
      }
      if (value) {
        answerInput($scope).val(value).trigger('change').focus();
        $('.qr-scanner', $scope).addClass('completely-hidden');
      } else {
        $status.text('The tag did not contain a text or URI record.');
      }
    }, {once: true});
  }).catch(function(error) {
    $('.qr-scanner', $scope).removeClass('completely-hidden');
    $status.text('NFC unavailable. Enter the answer manually. ' + error.message);
  });
}

module.exports = {
  init: function() {
    $(document).on('click', '.js-scan-qr', function(event) {
      event.preventDefault();
      scanQr($(this).closest('.fb-modal-content'));
    });
    $(document).on('click', '.js-scan-nfc', function(event) {
      event.preventDefault();
      scanNfc($(this).closest('.fb-modal-content'));
    });
    $(document).on('click', '.js-close-scanner, .js-close-modal', function() {
      stopScanner($(this).closest('.fb-modal-content'));
    });
  }
};
