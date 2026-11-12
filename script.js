/**
 * Kirinyaga University | Smart Campus front-end behaviour
 * 1 Client-side validation: school email rule, M-Pesa code format and the usual field rules
 * 2 Live room filter on the homepage
 * 3 Room application: live fee breakdown and a check that a room was picked
 * 4 Hostel cards: pointer-driven 3D tilt (skipped when motion is reduced)
 * 5 Data-attribute helpers: confirmations, printing, auto-submitting filters
 * 6 Opens the admin tab named in the URL hash, e.g. admin_dashboard.php#tickets
 */
(() => {
  'use strict';

  const MPESA = 'M-Pesa Express / Paybill';

  const RULES = {
    reg: (v) => /^[A-Z]{2}\d{3}\/[A-Z]\/\d{4,5}\/\d{2}$/.test(v.trim().toUpperCase()),
    phone: (v) => /^\d{10}$/.test(v.trim()),
    kyuemail: (v) => /^[a-z0-9._%+-]+@(student\.kyu\.ac\.ke|kyu\.ac\.ke)$/i.test(v.trim()),
    name: (v) => /^[A-Za-z][A-Za-z .'-]{2,99}$/.test(v.trim()),
    password: (v) => /^(?=.*[A-Za-z])(?=.*\d).{8,}$/.test(v),
    money: (v) => /^\d+(\.\d{1,2})?$/.test(v) && parseFloat(v) > 0,
    text: (v) => v.trim().length >= 10 && v.trim().length <= 500,
    reason: (v) => v.trim().length >= 5 && v.trim().length <= 255,
    required: (v) => v.trim() !== '',
    select: (v) => v !== '',
  };

  // Checks one field, toggles Bootstrap's is-valid / is-invalid classes and returns the result.
  const checkField = (field, form) => {
    const rule = field.dataset.rule;
    let ok;
    if (rule === 'checked') {
      ok = field.checked;
    } else if (rule === 'match') {
      const other = form.querySelector(field.dataset.match);
      ok = field.value !== '' && other !== null && field.value === other.value;
    } else if (rule === 'paycode') {
      const methodField = form.querySelector(field.dataset.method);
      const method = methodField ? methodField.value : '';
      const code = field.value.trim().toUpperCase();
      ok = method === MPESA ? /^[A-Z0-9]{10}$/.test(code) : /^[A-Z0-9\-\/]{6,20}$/.test(code);
    } else {
      ok = (RULES[rule] || (() => true))(field.value);
    }
    field.classList.toggle('is-invalid', !ok);
    field.classList.toggle('is-valid', ok);
    return ok;
  };

  // 1. Forms marked data-validate
  document.querySelectorAll('form[data-validate]').forEach((form) => {
    const fields = form.querySelectorAll('[data-rule]');
    const paymentCodes = form.querySelectorAll('[data-rule="paycode"]');

    fields.forEach((field) => {
      field.addEventListener('blur', () => checkField(field, form));
      field.addEventListener('change', () => {
        checkField(field, form);
        paymentCodes.forEach((code) => checkField(code, form));
      });
      field.addEventListener('input', () => {
        if (field.classList.contains('is-invalid')) checkField(field, form);
      });
    });

    // M-Pesa codes are shown and checked in capitals.
    paymentCodes.forEach((code) => {
      code.addEventListener('input', () => {
        const caret = code.selectionStart;
        code.value = code.value.toUpperCase();
        code.setSelectionRange(caret, caret);
        if (code.classList.contains('is-invalid') || code.classList.contains('is-valid')) {
          checkField(code, form);
        }
      });
    });

    form.addEventListener('submit', (event) => {
      let valid = true;
      fields.forEach((field) => {
        if (!checkField(field, form)) valid = false;
      });
      if (!valid) {
        event.preventDefault();
        form.querySelector('.is-invalid')?.focus();
      }
    });
  });

  // 2. Homepage room filter (search text, hostel and free-beds)
  document.querySelectorAll('[data-room-filter]').forEach((box) => {
    const search = box.querySelector('#fSearch');
    const hostel = box.querySelector('#fHostel');
    const freeOnly = box.querySelector('#fAvail');
    const rows = box.querySelectorAll('.room-row');
    const emptyState = box.querySelector('#noRooms');

    const applyFilters = () => {
      const query = search.value.trim().toLowerCase();
      let visible = 0;
      rows.forEach((row) => {
        const show = (!query || row.dataset.text.includes(query))
          && (!hostel.value || row.dataset.hostel === hostel.value)
          && (!freeOnly.checked || row.dataset.free === '1');
        row.classList.toggle('d-none', !show);
        if (show) visible += 1;
      });
      emptyState.classList.toggle('d-none', visible > 0);
    };

    [search, hostel, freeOnly].forEach((control) => control.addEventListener('input', applyFilters));
    applyFilters();
  });

  // 3. Room application: live fee breakdown and a check that a room was picked
  const roomForm = document.querySelector('[data-room-form]');
  if (roomForm) {
    const roomError = document.getElementById('roomError');
    const money = (value) => 'KES ' + Number(value).toLocaleString('en-US', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
    const setField = (name, text) => {
      const target = roomForm.querySelector(`[data-field="${name}"]`);
      if (target) target.textContent = text;
    };

    const refresh = () => {
      const picked = roomForm.querySelector('input[name="room_id"]:checked');
      const semester = roomForm.querySelector('select[name="semester"]');
      setField('semester', semester && semester.value !== '' ? semester.value : 'Not selected');

      if (!picked) {
        setField('hostel', 'Not selected');
        setField('room', '-');
        setField('beds', '-');
        setField('fee', money(0));
        setField('balance', money(0));
        return;
      }

      roomError?.classList.add('d-none');
      const fee = Number(picked.dataset.fee);
      setField('hostel', picked.dataset.hostel);
      setField('room', picked.dataset.room);
      setField('beds', `${picked.dataset.capacity}-bed room, ${picked.dataset.free} free`);
      setField('fee', money(fee));
      setField('balance', money(fee));
    };

    roomForm.addEventListener('change', refresh);
    roomForm.addEventListener('submit', (event) => {
      if (!roomForm.querySelector('input[name="room_id"]:checked')) {
        event.preventDefault();
        roomError?.classList.remove('d-none');
        roomError?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });
    refresh();
  }

  // 4. Hostel cards: a gentle 3D tilt that follows the pointer
  if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    document.querySelectorAll('.hostel-tilt').forEach((tile) => {
      const panel = tile.querySelector('.hostel-card');
      if (!panel) return;

      tile.addEventListener('pointermove', (event) => {
        const rect = tile.getBoundingClientRect();
        const x = (event.clientX - rect.left) / rect.width - 0.5;
        const y = (event.clientY - rect.top) / rect.height - 0.5;
        panel.style.setProperty('--tilt-x', `${(-y * 8).toFixed(2)}deg`);
        panel.style.setProperty('--tilt-y', `${(x * 10).toFixed(2)}deg`);
      });

      tile.addEventListener('pointerleave', () => {
        panel.style.setProperty('--tilt-x', '0deg');
        panel.style.setProperty('--tilt-y', '0deg');
      });
    });
  }

  // 5. Data-attribute helpers (keeps the markup free of inline JavaScript)
  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
  });

  document.querySelectorAll('[data-print]').forEach((button) => {
    button.addEventListener('click', () => window.print());
  });

  document.querySelectorAll('[data-autosubmit]').forEach((control) => {
    control.addEventListener('change', () => control.form?.submit());
  });

  // 6. Open the admin tab named in the URL hash, e.g. admin_dashboard.php#tickets
  if (window.bootstrap && /^#[a-z]+$/.test(location.hash)) {
    const trigger = document.querySelector(`[data-bs-target="${location.hash}Pane"]`);
    if (trigger) bootstrap.Tab.getOrCreateInstance(trigger).show();
  }
})();
