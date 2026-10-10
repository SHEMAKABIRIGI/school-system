// Enhance browser validation with clear invalid-field states on public forms.
document.addEventListener('DOMContentLoaded', function () {
  var forms = document.querySelectorAll('form[data-validate]');

  forms.forEach(function (form) {
    var fields = form.querySelectorAll('input, textarea, select');

    function updateField(field) {
      if (field.type === 'tel' && field.value !== '') {
        var phone = field.value.trim();
        var digitCount = phone.replace(/[^0-9]/g, '').length;
        var validPhone = /^[0-9+().\s-]{7,20}$/u.test(phone) && digitCount >= 7;
        field.setCustomValidity(validPhone ? '' : 'Use at least 7 digits and no more than 20 characters.');
      } else if (field.type === 'tel') {
        field.setCustomValidity('');
      }

      if (field.validity.valid) {
        field.removeAttribute('aria-invalid');
      } else {
        field.setAttribute('aria-invalid', 'true');
      }
    }

    fields.forEach(function (field) {
      field.addEventListener('blur', function () {
        updateField(field);
      });
      field.addEventListener('input', function () {
        updateField(field);
      });
      field.addEventListener('invalid', function () {
        field.setAttribute('aria-invalid', 'true');
      });
    });

    form.addEventListener('submit', function (event) {
      var firstInvalid = null;
      fields.forEach(function (field) {
        updateField(field);
        if (!field.validity.valid && firstInvalid === null) firstInvalid = field;
      });
      if (firstInvalid) {
        event.preventDefault();
        firstInvalid.focus();
        firstInvalid.reportValidity();
      }
    });
  });
});
