(() => {
  'use strict';
  document.documentElement.classList.add('js');

  const sidebar = document.querySelector('[data-sidebar]');
  const openButton = document.querySelector('[data-sidebar-toggle]');
  const closeButton = document.querySelector('[data-sidebar-close]');
  const scrim = document.querySelector('[data-sidebar-scrim]');
  const desktop = window.matchMedia('(min-width: 1024px)');
  let returnFocus = null;
  const focusableSelector = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

  const setDrawerState = (isOpen, restoreFocus = true) => {
    if (!sidebar || !openButton || !scrim) return;
    sidebar.classList.toggle('is-open', isOpen);
    scrim.classList.toggle('is-visible', isOpen);
    document.body.classList.toggle('nav-open', isOpen);
    openButton.setAttribute('aria-expanded', String(isOpen));
    openButton.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
    if (desktop.matches) { sidebar.removeAttribute('aria-hidden'); return; }
    sidebar.setAttribute('aria-hidden', String(!isOpen));
    if (isOpen) {
      returnFocus = document.activeElement;
      const target = sidebar.querySelector(focusableSelector);
      if (target instanceof HTMLElement) requestAnimationFrame(() => target.focus());
    } else if (restoreFocus && returnFocus instanceof HTMLElement) {
      returnFocus.focus();
    }
  };

  const syncDrawer = () => {
    if (!sidebar || !openButton || !scrim) return;
    sidebar.classList.remove('is-open');
    scrim.classList.remove('is-visible');
    document.body.classList.remove('nav-open');
    openButton.setAttribute('aria-expanded', 'false');
    desktop.matches ? sidebar.removeAttribute('aria-hidden') : sidebar.setAttribute('aria-hidden', 'true');
  };

  openButton?.addEventListener('click', () => setDrawerState(!sidebar?.classList.contains('is-open')));
  closeButton?.addEventListener('click', () => setDrawerState(false));
  scrim?.addEventListener('click', () => setDrawerState(false));
  sidebar?.querySelectorAll('a[href]').forEach((link) => link.addEventListener('click', () => {
    if (!desktop.matches) setDrawerState(false, false);
  }));

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && sidebar?.classList.contains('is-open')) { setDrawerState(false); return; }
    if (event.key !== 'Tab' || !sidebar?.classList.contains('is-open') || desktop.matches) return;
    const focusable = [...sidebar.querySelectorAll(focusableSelector)].filter((item) => item instanceof HTMLElement);
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  });
  typeof desktop.addEventListener === 'function' ? desktop.addEventListener('change', syncDrawer) : desktop.addListener(syncDrawer);
  syncDrawer();

  document.querySelectorAll('[data-password-toggle]').forEach((button) => button.addEventListener('click', () => {
    const input = document.getElementById(button.getAttribute('data-password-toggle') || '');
    if (!(input instanceof HTMLInputElement)) return;
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    const passwordName = button.getAttribute('data-password-name') || 'password';
    const label = `${show ? 'Hide' : 'Show'} ${passwordName}`;
    button.setAttribute('aria-label', label);
    button.setAttribute('data-password-visible', show ? 'true' : 'false');
    button.setAttribute('title', label);
    input.focus({ preventScroll: true });
  }));

  const errorSummary = document.querySelector('[data-error-summary]');
  if (errorSummary instanceof HTMLElement) requestAnimationFrame(() => errorSummary.focus());

  const oidcContinue = document.querySelector('[data-oidc-continue]');
  if (oidcContinue instanceof HTMLAnchorElement) {
    requestAnimationFrame(() => window.location.replace(oidcContinue.href));
  }

  const blockNotice = document.querySelector('[data-login-block]');
  const blockCountdown = blockNotice?.querySelector('[data-block-countdown]');
  const loginSubmit = document.querySelector('[data-login-submit]');
  if (blockNotice instanceof HTMLElement && blockCountdown && loginSubmit instanceof HTMLButtonElement) {
    let remaining = Number.parseInt(blockNotice.dataset.blockedFor || '0', 10);
    if (remaining > 0) {
      const timer = window.setInterval(() => {
        remaining -= 1;
        if (remaining <= 0) { clearInterval(timer); blockNotice.remove(); loginSubmit.disabled = false; return; }
        blockCountdown.textContent = `${remaining} second${remaining === 1 ? '' : 's'}`;
      }, 1000);
    }
  }

  document.querySelectorAll('form[data-confirm]').forEach((form) => form.addEventListener('submit', (event) => {
    const message = form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) event.preventDefault();
  }));

  document.querySelectorAll('[data-bag-preset-entry]').forEach((entry) => {
    const label = entry.querySelector('[data-bag-preset-label]');
    const unit = entry.querySelector('[data-bag-preset-unit]');
    const fields = [...entry.querySelectorAll('[data-bag-preset-field]')];
    const updateLabel = () => {
      if (!(label instanceof HTMLElement)) return;
      const value = (name) => {
        const input = fields.find((field) => field instanceof HTMLInputElement && field.dataset.bagPresetField === name);
        return input instanceof HTMLInputElement ? input.value.trim() : '';
      };
      const brand = value('brand');
      const dimensions = `${value('width') || '—'} × ${value('length') || '—'} ${unit instanceof HTMLInputElement && unit.value === 'in' ? 'in' : 'mm'} · ${value('micron') || '—'} μm`;
      label.textContent = brand === '' ? dimensions : `${brand} · ${dimensions}`;
    };
    fields.forEach((field) => field.addEventListener('input', updateLabel));
  });

  const materialMarkers = new Set(['burst', 'dot', 'diamond', 'square', 'triangle', 'cross']);
  document.querySelectorAll('form.material-preset-edit').forEach((form) => {
    if (!(form instanceof HTMLFormElement)) return;
    const currentPreview = form.querySelector('.material-style-current__marker');
    const replaceMarkerFragment = (use, marker) => {
      if (!(use instanceof Element)) return;
      const href = use.getAttribute('href') || '';
      const hashIndex = href.indexOf('#');
      const prefix = hashIndex >= 0 ? href.slice(0, hashIndex) : href;
      use.setAttribute('href', `${prefix}#marker-${marker}`);
    };
    const syncMaterialStylePreviews = () => {
      const markerInput = form.querySelector('input[name="chart_marker"]:checked');
      const colorInput = form.querySelector('input[name="chart_color"]:checked');
      const marker = markerInput instanceof HTMLInputElement ? markerInput.value : '';
      const color = colorInput instanceof HTMLInputElement ? colorInput.value : '';
      if (!materialMarkers.has(marker)) return;

      form.querySelectorAll('.material-style-choices--colors .material-style-choice__preview use')
        .forEach((use) => replaceMarkerFragment(use, marker));
      if (currentPreview instanceof SVGElement) {
        const currentUse = currentPreview.querySelector('use');
        replaceMarkerFragment(currentUse, marker);
        if (/^#[0-9a-f]{6}$/i.test(color)) currentPreview.setAttribute('color', color);
      }
      if (/^#[0-9a-f]{6}$/i.test(color)) {
        form.querySelectorAll('.material-style-choices--markers .material-style-choice__preview')
          .forEach((preview) => preview.setAttribute('color', color));
      }
    };

    form.addEventListener('change', (event) => {
      const input = event.target instanceof Element ? event.target.closest('input[name="chart_marker"],input[name="chart_color"]') : null;
      if (input instanceof HTMLInputElement) syncMaterialStylePreviews();
    });
    syncMaterialStylePreviews();
  });

  const dialogOpeners = new WeakMap();
  const closeDialog = (dialog) => {
    if (!(dialog instanceof HTMLDialogElement)) return;
    if (typeof dialog.close === 'function') dialog.close();
    else dialog.removeAttribute('open');
  };

  document.querySelectorAll('[data-dialog-open]').forEach((button) => button.addEventListener('click', () => {
    const dialog = document.getElementById(button.getAttribute('data-dialog-open') || '');
    if (!(dialog instanceof HTMLDialogElement)) return;
    dialogOpeners.set(dialog, button);
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
    requestAnimationFrame(() => dialog.querySelector('input:not([type="hidden"]),button')?.focus());
  }));

  document.querySelectorAll('dialog').forEach((dialog) => {
    dialog.querySelectorAll('[data-dialog-close]').forEach((button) => button.addEventListener('click', () => closeDialog(dialog)));
    dialog.addEventListener('click', (event) => { if (event.target === dialog) closeDialog(dialog); });
    dialog.addEventListener('close', () => {
      const opener = dialogOpeners.get(dialog);
      if (opener instanceof HTMLElement) opener.focus();
    });
  });

  const batchForm = document.querySelector('[data-batch-form]');
  if (batchForm instanceof HTMLFormElement) {
    const startAmount = batchForm.querySelector('[data-start-amount]');
    const yieldAmount = batchForm.querySelector('[data-yield-amount]');
    const yieldPercentage = batchForm.querySelector('[data-yield-percentage]');
    const updateYield = () => {
      if (!(yieldPercentage instanceof HTMLOutputElement)) return;
      const start = Number.parseFloat(startAmount?.value || '');
      const result = Number.parseFloat(yieldAmount?.value || '');
      yieldPercentage.value = Number.isFinite(start) && start > 0 && Number.isFinite(result)
        ? `${(result / start * 100).toFixed(1)}%`
        : '0.0%';
    };
    yieldAmount?.addEventListener('input', updateYield);
    const strainRows = batchForm.querySelector('[data-strain-rows]');
    const strainAmountRows = batchForm.querySelector('[data-strain-amount-rows]');
    let manualStartAmount = startAmount instanceof HTMLInputElement ? startAmount.value : '';
    let strainAmountsActive = false;
    let openSavedCombobox = null;

    const comboboxParts = (control) => ({
      input: control?.querySelector('[data-combobox-input]'),
      toggle: control?.querySelector('[data-combobox-toggle]'),
      list: control?.querySelector('[data-combobox-list]'),
      options: [...(control?.querySelectorAll('[data-combobox-option]') || [])],
      empty: control?.querySelector('[data-combobox-empty]'),
    });
    const visibleComboboxOptions = (control) => comboboxParts(control).options.filter((option) => !option.hidden);
    const setComboboxActiveOption = (control, option = null) => {
      const { input, options } = comboboxParts(control);
      options.forEach((candidate) => candidate.setAttribute('aria-selected', String(candidate === option)));
      if (!(input instanceof HTMLInputElement)) return;
      if (option instanceof HTMLElement && option.id !== '') {
        input.setAttribute('aria-activedescendant', option.id);
        option.scrollIntoView({ block: 'nearest' });
      } else {
        input.removeAttribute('aria-activedescendant');
      }
    };
    const filterCombobox = (control, query = '') => {
      const { options, empty } = comboboxParts(control);
      const needle = query.trim().toLocaleLowerCase();
      let visible = 0;
      options.forEach((option) => {
        const value = String(option.dataset.value || option.textContent || '').toLocaleLowerCase();
        option.hidden = needle !== '' && !value.includes(needle);
        if (!option.hidden) visible += 1;
      });
      if (empty instanceof HTMLElement) empty.hidden = visible > 0;
      setComboboxActiveOption(control);
    };
    const closeCombobox = (control, restoreFocus = false) => {
      if (!(control instanceof HTMLElement)) return;
      const { input, toggle, list } = comboboxParts(control);
      control.classList.remove('is-open');
      if (list instanceof HTMLElement) list.hidden = true;
      if (input instanceof HTMLInputElement) {
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
      }
      if (toggle instanceof HTMLButtonElement) toggle.setAttribute('aria-expanded', 'false');
      setComboboxActiveOption(control);
      if (openSavedCombobox === control) openSavedCombobox = null;
      if (restoreFocus && input instanceof HTMLInputElement) input.focus({ preventScroll: true });
    };
    const showCombobox = (control, filterCurrentValue = false) => {
      if (!(control instanceof HTMLElement)) return;
      if (openSavedCombobox instanceof HTMLElement && openSavedCombobox !== control) closeCombobox(openSavedCombobox);
      const { input, toggle, list } = comboboxParts(control);
      if (!(input instanceof HTMLInputElement) || !(list instanceof HTMLElement)) return;
      filterCombobox(control, filterCurrentValue ? input.value : '');
      control.classList.add('is-open');
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      if (toggle instanceof HTMLButtonElement) toggle.setAttribute('aria-expanded', 'true');
      openSavedCombobox = control;
    };
    const chooseComboboxOption = (control, option) => {
      const { input } = comboboxParts(control);
      if (!(input instanceof HTMLInputElement) || !(option instanceof HTMLElement)) return;
      input.value = String(option.dataset.value || option.textContent || '');
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
      closeCombobox(control, true);
    };
    const moveComboboxActiveOption = (control, direction) => {
      const options = visibleComboboxOptions(control);
      if (options.length === 0) return;
      const { input } = comboboxParts(control);
      const activeId = input instanceof HTMLInputElement ? input.getAttribute('aria-activedescendant') : '';
      const current = options.findIndex((option) => option.id === activeId);
      const next = current < 0
        ? (direction > 0 ? 0 : options.length - 1)
        : (current + direction + options.length) % options.length;
      setComboboxActiveOption(control, options[next]);
    };
    const configureCombobox = (control, baseId) => {
      if (!(control instanceof HTMLElement)) return;
      const { input, toggle, list, options } = comboboxParts(control);
      if (!(input instanceof HTMLInputElement) || !(list instanceof HTMLElement)) return;
      input.id = baseId;
      list.id = `${baseId}-options`;
      input.setAttribute('aria-controls', list.id);
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
      if (toggle instanceof HTMLButtonElement) {
        toggle.setAttribute('aria-controls', list.id);
        toggle.setAttribute('aria-expanded', 'false');
      }
      options.forEach((option, index) => {
        option.id = `${baseId}-option-${index}`;
        option.hidden = false;
        option.setAttribute('aria-selected', 'false');
      });
      control.classList.remove('is-open');
      list.hidden = true;
    };
    const cloneStrainAmountRow = () => {
      if (!(strainAmountRows instanceof HTMLElement)) return null;
      const source = strainAmountRows.querySelector('[data-strain-amount-row]');
      if (!(source instanceof HTMLElement)) return null;
      const row = source.cloneNode(true);
      row.querySelectorAll('.field-error').forEach((error) => error.remove());
      const input = row.querySelector('[data-strain-amount]');
      if (input instanceof HTMLInputElement) {
        input.value = '';
        input.required = false;
        input.removeAttribute('aria-invalid');
      }
      strainAmountRows.appendChild(row);
      return row;
    };
    const updateStartAmountFromStrains = () => {
      const fields = [...(strainAmountRows?.querySelectorAll('[data-strain-amount]') || [])]
        .filter((field) => field instanceof HTMLInputElement);
      const hasAny = fields.some((field) => field.value.trim() !== '');
      if (hasAny && !strainAmountsActive && startAmount instanceof HTMLInputElement) {
        manualStartAmount = startAmount.value;
      }
      fields.forEach((field) => { field.required = hasAny; });
      if (startAmount instanceof HTMLInputElement) {
        startAmount.readOnly = hasAny;
        startAmount.classList.toggle('is-calculated', hasAny);
        startAmount.setAttribute('aria-readonly', String(hasAny));
        if (hasAny) {
          const values = fields.map((field) => field.value.trim() === '' ? 0 : Number.parseFloat(field.value));
          if (values.every((value) => Number.isFinite(value) && value >= 0)) {
            const sum = values.reduce((total, value) => total + value, 0);
            startAmount.value = String(Math.round((sum + Number.EPSILON) * 100000) / 100000);
          }
        } else if (strainAmountsActive) {
          startAmount.value = manualStartAmount;
        }
      }
      strainAmountsActive = hasAny;
      updateYield();
    };
    const renumberStrains = () => {
      if (!(strainRows instanceof HTMLElement) || !(strainAmountRows instanceof HTMLElement)) return;
      const rows = [...strainRows.querySelectorAll('[data-strain-row]')];
      let amountRows = [...strainAmountRows.querySelectorAll('[data-strain-amount-row]')];
      while (amountRows.length < rows.length) {
        if (!(cloneStrainAmountRow() instanceof HTMLElement)) break;
        amountRows = [...strainAmountRows.querySelectorAll('[data-strain-amount-row]')];
      }
      while (amountRows.length > rows.length) amountRows.pop()?.remove();
      amountRows = [...strainAmountRows.querySelectorAll('[data-strain-amount-row]')];
      rows.forEach((row, index) => {
        const input = row.querySelector('[data-combobox-input]');
        if (input instanceof HTMLInputElement) {
          input.required = index === 0;
          input.setAttribute('aria-label', `Strain ${index + 1}`);
          configureCombobox(input.closest('[data-saved-combobox]'), `strain-${index}`);
        }
        const amountRow = amountRows[index];
        const amount = amountRow?.querySelector('[data-strain-amount]');
        const label = amountRow?.querySelector('[data-strain-amount-label]');
        if (amount instanceof HTMLInputElement) {
          amount.id = `strain-amount-${index}`;
          amount.name = 'strain_amounts[]';
          amountRow?.querySelector('label')?.setAttribute('for', amount.id);
        }
        if (label) label.textContent = input instanceof HTMLInputElement && input.value.trim() !== ''
          ? input.value.trim()
          : `Strain ${index + 1}`;
      });
      if (rows.length < 2) {
        const remainingAmount = amountRows[0]?.querySelector('[data-strain-amount]');
        const retainedValue = remainingAmount instanceof HTMLInputElement ? remainingAmount.value.trim() : '';
        amountRows.forEach((row) => {
          const amount = row.querySelector('[data-strain-amount]');
          if (!(amount instanceof HTMLInputElement)) return;
          amount.value = '';
          amount.required = false;
          amount.disabled = true;
        });
        if (startAmount instanceof HTMLInputElement) {
          if (retainedValue !== '') startAmount.value = retainedValue;
          else if (strainAmountsActive) startAmount.value = manualStartAmount;
          startAmount.readOnly = false;
          startAmount.classList.remove('is-calculated');
          startAmount.setAttribute('aria-readonly', 'false');
          manualStartAmount = startAmount.value;
        }
        strainAmountsActive = false;
        strainAmountRows.classList.add('is-hidden');
        updateYield();
        return;
      }
      amountRows.forEach((row) => {
        const amount = row.querySelector('[data-strain-amount]');
        if (amount instanceof HTMLInputElement) amount.disabled = false;
      });
      strainAmountRows.classList.remove('is-hidden');
      updateStartAmountFromStrains();
    };
    const addStrainRow = () => {
      if (!(strainRows instanceof HTMLElement)) return;
      const source = strainRows.querySelector('[data-strain-row]');
      if (!(source instanceof HTMLElement)) return;
      const row = source.cloneNode(true);
      const input = row.querySelector('input[name="strains[]"]');
      if (input instanceof HTMLInputElement) {
        input.value = '';
        input.removeAttribute('aria-invalid');
      }
      row.querySelectorAll('.field-error').forEach((error) => error.remove());
      const control = row.querySelector('[data-saved-combobox]');
      const list = control?.querySelector('[data-combobox-list]');
      control?.classList.remove('is-open');
      if (list instanceof HTMLElement) list.hidden = true;
      strainRows.appendChild(row);
      renumberStrains();
      return row;
    };
    batchForm.querySelector('[data-add-strain]')?.addEventListener('click', () => {
      addStrainRow()?.querySelector('[data-combobox-input]')?.focus();
    });
    strainRows?.addEventListener('click', (event) => {
      const button = event.target instanceof Element ? event.target.closest('[data-remove-row]') : null;
      const row = button?.closest('[data-strain-row]');
      if (!(row instanceof HTMLElement) || !(strainRows instanceof HTMLElement)) return;
      const rows = [...strainRows.querySelectorAll('[data-strain-row]')];
      const index = rows.indexOf(row);
      const amountRows = [...(strainAmountRows?.querySelectorAll('[data-strain-amount-row]') || [])];
      if (rows.length === 1) {
        const input = row.querySelector('input[name="strains[]"]');
        if (input instanceof HTMLInputElement) { input.value = ''; input.focus(); }
        const amount = amountRows[0]?.querySelector('[data-strain-amount]');
        if (amount instanceof HTMLInputElement) amount.value = '';
      } else {
        row.remove();
        amountRows[index]?.remove();
        strainRows.querySelector('input[name="strains[]"]')?.focus();
      }
      renumberStrains();
    });
    strainRows?.addEventListener('input', (event) => {
      if (event.target instanceof HTMLInputElement && event.target.matches('input[name="strains[]"]')) renumberStrains();
    });
    strainAmountRows?.addEventListener('input', updateStartAmountFromStrains);
    startAmount?.addEventListener('input', () => {
      if (!strainAmountsActive && startAmount instanceof HTMLInputElement) manualStartAmount = startAmount.value;
      updateYield();
    });

    batchForm.querySelectorAll('[data-saved-combobox]').forEach((control, index) => {
      const input = control.querySelector('[data-combobox-input]');
      if (!(input instanceof HTMLInputElement)) return;
      configureCombobox(control, input.name === 'start_material' ? 'start-material' : `saved-option-${index}`);
    });
    batchForm.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target : null;
      const option = target?.closest('[data-combobox-option]');
      if (option instanceof HTMLElement) {
        chooseComboboxOption(option.closest('[data-saved-combobox]'), option);
        return;
      }
      const toggle = target?.closest('[data-combobox-toggle]');
      if (toggle instanceof HTMLButtonElement) {
        const control = toggle.closest('[data-saved-combobox]');
        if (openSavedCombobox === control) closeCombobox(control, true);
        else { showCombobox(control); comboboxParts(control).input?.focus({ preventScroll: true }); }
        return;
      }
      const input = target?.closest('[data-combobox-input]');
      if (input instanceof HTMLInputElement) showCombobox(input.closest('[data-saved-combobox]'));
    });
    batchForm.addEventListener('input', (event) => {
      const input = event.target instanceof Element ? event.target.closest('[data-combobox-input]') : null;
      if (!(input instanceof HTMLInputElement)) return;
      const control = input.closest('[data-saved-combobox]');
      showCombobox(control, true);
    });
    batchForm.addEventListener('keydown', (event) => {
      const input = event.target instanceof Element ? event.target.closest('[data-combobox-input]') : null;
      if (!(input instanceof HTMLInputElement)) return;
      const control = input.closest('[data-saved-combobox]');
      if (!(control instanceof HTMLElement)) return;
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        if (openSavedCombobox !== control) showCombobox(control);
        moveComboboxActiveOption(control, event.key === 'ArrowDown' ? 1 : -1);
      } else if (event.key === 'Enter' && openSavedCombobox === control) {
        const active = comboboxParts(control).options.find((option) => option.getAttribute('aria-selected') === 'true');
        if (active instanceof HTMLElement) { event.preventDefault(); chooseComboboxOption(control, active); }
      } else if (event.key === 'Escape' && openSavedCombobox === control) {
        event.preventDefault();
        closeCombobox(control, true);
      } else if (event.key === 'Tab' && openSavedCombobox === control) {
        closeCombobox(control);
      }
    });
    document.addEventListener('pointerdown', (event) => {
      if (openSavedCombobox instanceof HTMLElement && !openSavedCombobox.contains(event.target)) closeCombobox(openSavedCombobox);
    });
    renumberStrains();
    updateYield();

    const passSection = batchForm.querySelector('[data-pass-list]');
    const passRows = passSection?.querySelector('[data-pass-rows]');
    const passTemplate = passSection?.querySelector('[data-pass-template]');
    const addPassButton = passSection?.querySelector('[data-add-pass]');
    const passStatus = passSection?.querySelector('[data-pass-status]');
    const maxPasses = Math.max(1, Number.parseInt(passSection?.dataset.maxPasses || '20', 10) || 20);
    const readPassRow = (row) => {
      if (!(row instanceof HTMLElement)) return {};
      const values = {};
      row.querySelectorAll('[data-pass-field]').forEach((field) => {
        if (field instanceof HTMLInputElement) values[field.dataset.passField || ''] = field.value;
      });
      return values;
    };
    const populatePassRow = (row, values) => {
      if (!(row instanceof HTMLElement) || !values || typeof values !== 'object') return;
      const aliases = {
        temperature: ['temperature', 'temperatureC', 'temperature_c'],
        pressure: ['pressure', 'pressureBar', 'pressure_bar'],
        press_duration: ['press_duration', 'pressDurationSeconds', 'press_duration_seconds'],
        preheat: ['preheat', 'preheatSeconds', 'preheat_seconds'],
      };
      Object.entries(aliases).forEach(([fieldName, keys]) => {
        const field = row.querySelector(`[data-pass-field="${fieldName}"]`);
        if (!(field instanceof HTMLInputElement)) return;
        const key = keys.find((candidate) => values[candidate] !== undefined && values[candidate] !== null);
        field.value = key === undefined ? '' : String(values[key]);
        field.removeAttribute('aria-invalid');
      });
      row.querySelectorAll('.field-error').forEach((error) => error.remove());
    };
    const renamePassRows = () => {
      if (!(passRows instanceof HTMLElement)) return;
      const rows = [...passRows.querySelectorAll('[data-pass-row]')];
      rows.forEach((row, index) => {
        row.querySelectorAll('[name]').forEach((field) => {
          const name = field.getAttribute('name');
          if (name) field.setAttribute('name', name.replace(/passes\[(?:__INDEX__|\d+)\]/, `passes[${index}]`));
        });
        row.querySelectorAll('[data-pass-field]').forEach((field) => {
          const fieldName = field instanceof HTMLElement ? field.dataset.passField : '';
          if (!fieldName) return;
          const id = `pass-${index}-${fieldName}`;
          field.id = id;
          field.closest('.field-group')?.querySelector('label')?.setAttribute('for', id);
        });
        const label = row.querySelector('[data-pass-label]');
        if (label) label.textContent = String(index + 1);
        const remove = row.querySelector('[data-remove-pass]');
        if (remove instanceof HTMLButtonElement) {
          remove.disabled = rows.length === 1;
          remove.setAttribute('aria-label', `Remove Pass ${index + 1}`);
        }
      });
      if (addPassButton instanceof HTMLButtonElement) addPassButton.disabled = rows.length >= maxPasses;
      if (passStatus) passStatus.textContent = `${rows.length} of ${maxPasses} passes`;
    };
    const addPassRow = (values = null) => {
      if (!(passRows instanceof HTMLElement) || !(passTemplate instanceof HTMLTemplateElement)) return null;
      if (passRows.querySelectorAll('[data-pass-row]').length >= maxPasses) return null;
      const fragment = passTemplate.content.cloneNode(true);
      const row = fragment.querySelector('[data-pass-row]');
      passRows.appendChild(fragment);
      renamePassRows();
      if (row instanceof HTMLElement && values) populatePassRow(row, values);
      return row instanceof HTMLElement ? row : null;
    };
    addPassButton?.addEventListener('click', () => {
      if (!(passRows instanceof HTMLElement)) return;
      const rows = [...passRows.querySelectorAll('[data-pass-row]')];
      const previous = rows.length > 0 ? readPassRow(rows[rows.length - 1]) : {};
      const row = addPassRow(previous);
      row?.querySelector('[data-pass-field="temperature"]')?.focus();
    });
    passRows?.addEventListener('click', (event) => {
      const button = event.target instanceof Element ? event.target.closest('[data-remove-pass]') : null;
      const row = button?.closest('[data-pass-row]');
      if (!(row instanceof HTMLElement) || !(passRows instanceof HTMLElement)) return;
      if (passRows.querySelectorAll('[data-pass-row]').length <= 1) return;
      const nextFocus = row.nextElementSibling || row.previousElementSibling;
      row.remove();
      renamePassRows();
      nextFocus?.querySelector('[data-pass-field="temperature"]')?.focus();
    });
    renamePassRows();

    const bagSection = batchForm.querySelector('[data-bag-list]');
    const bagRows = bagSection?.querySelector('[data-bag-rows]');
    const bagTemplate = bagSection?.querySelector('[data-bag-template]');
    const bagEmpty = bagSection?.querySelector('[data-bag-empty]');
    const renameBagRows = () => {
      if (!(bagRows instanceof HTMLElement)) return;
      const rows = [...bagRows.querySelectorAll('[data-bag-row]')];
      rows.forEach((row, index) => {
        row.querySelectorAll('[name]').forEach((field) => {
          const name = field.getAttribute('name');
          if (name) field.setAttribute('name', name.replace(/bags\[(?:__INDEX__|\d+)\]/, `bags[${index}]`));
        });
        const layer = index + 1;
        row.querySelectorAll('[data-bag-field]').forEach((field) => {
          if (!(field instanceof HTMLElement)) return;
          const fieldName = field.dataset.bagField;
          if (!fieldName) return;
          const id = `bag-${index}-${fieldName}`;
          field.id = id;
          field.closest('.field-group')?.querySelector('label')?.setAttribute('for', id);
        });
        const label = row.querySelector('[data-layer-label]');
        const input = row.querySelector('[data-layer-input]');
        if (label) label.textContent = String(layer);
        if (input instanceof HTMLInputElement) input.value = String(layer);
      });
      bagEmpty?.classList.toggle('is-hidden', rows.length > 0);
    };
    const addBagRow = () => {
      if (!(bagRows instanceof HTMLElement) || !(bagTemplate instanceof HTMLTemplateElement)) return null;
      const fragment = bagTemplate.content.cloneNode(true);
      const row = fragment.querySelector('[data-bag-row]');
      bagRows.appendChild(fragment);
      renameBagRows();
      return row instanceof HTMLElement ? row : null;
    };
    const setBagUnit = (row, unit) => {
      const normalized = unit === 'in' ? 'in' : 'mm';
      const field = row.querySelector('input[name$="[unit]"]');
      if (field instanceof HTMLInputElement) field.value = normalized;
      row.querySelectorAll('.unit-label').forEach((label) => { label.textContent = normalized; });
      return normalized;
    };
    const applySavedBag = (select) => {
      if (!(select instanceof HTMLSelectElement)) return;
      const row = select.closest('[data-bag-row]');
      const option = select.selectedOptions[0];
      if (!(row instanceof HTMLElement) || !(option instanceof HTMLOptionElement)) return;
      const brand = row.querySelector('input[data-bag-brand]');
      if (option.dataset.retainedBagBrand !== undefined) {
        if (brand instanceof HTMLInputElement) brand.value = option.dataset.retainedBagBrand;
        return;
      }
      if (brand instanceof HTMLInputElement) brand.value = select.value === '' ? '' : (option.dataset.bagBrand || '');
      select.querySelector('[data-retained-bag-brand]')?.remove();
      if (select.value === '') return;
      const width = row.querySelector('input[name$="[width]"]');
      const length = row.querySelector('input[name$="[length]"]');
      const micron = row.querySelector('input[name$="[micron]"]');
      if (width instanceof HTMLInputElement) width.value = option.dataset.bagWidth || '';
      if (length instanceof HTMLInputElement) length.value = option.dataset.bagLength || '';
      if (micron instanceof HTMLInputElement) micron.value = option.dataset.bagMicron || '';
    };
    bagSection?.querySelector('[data-add-bag]')?.addEventListener('click', () => {
      const row = addBagRow();
      row?.querySelector('select, input:not([type="hidden"])')?.focus();
    });
    bagRows?.addEventListener('click', (event) => {
      const button = event.target instanceof Element ? event.target.closest('[data-remove-row]') : null;
      const row = button?.closest('[data-bag-row]');
      if (!(row instanceof HTMLElement)) return;
      row.remove();
      renameBagRows();
    });
    bagRows?.addEventListener('change', (event) => {
      const select = event.target instanceof Element ? event.target.closest('[data-bag-preset]') : null;
      applySavedBag(select);
    });
    renameBagRows();

    const pick = (record, ...keys) => {
      if (!record || typeof record !== 'object') return undefined;
      for (const key of keys) if (record[key] !== undefined && record[key] !== null) return record[key];
      return undefined;
    };
    const setField = (name, value) => {
      if (value === undefined || value === null) return;
      const field = batchForm.elements.namedItem(name);
      if (field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement) field.value = String(value);
    };
    const populateBagRow = (row, bag) => {
      if (!(row instanceof HTMLElement) || !bag || typeof bag !== 'object') return;
      const targetUnitField = batchForm.elements.namedItem('bag_size_unit');
      const targetUnit = targetUnitField instanceof HTMLInputElement && targetUnitField.value === 'in' ? 'in' : 'mm';
      let width = pick(bag, 'width');
      let length = pick(bag, 'length');
      let unit = pick(bag, 'unit') === 'in' ? 'in' : targetUnit;
      const hasCanonicalWidth = width === undefined && pick(bag, 'widthMm', 'width_mm') !== undefined;
      const hasCanonicalLength = length === undefined && pick(bag, 'lengthMm', 'length_mm') !== undefined;
      if (hasCanonicalWidth) width = pick(bag, 'widthMm', 'width_mm');
      if (hasCanonicalLength) length = pick(bag, 'lengthMm', 'length_mm');
      if ((hasCanonicalWidth || hasCanonicalLength) && targetUnit === 'in') {
        if (Number.isFinite(Number(width))) width = (Number(width) / 25.4).toFixed(2);
        if (Number.isFinite(Number(length))) length = (Number(length) / 25.4).toFixed(2);
        unit = 'in';
      }
      const assignments = {
        micron: pick(bag, 'micron'),
        width,
        length,
        brand: pick(bag, 'brand', 'bagBrand', 'bag_brand') || '',
      };
      Object.entries(assignments).forEach(([key, value]) => {
        const field = row.querySelector(`input[name$="[${key}]"]`);
        if (field instanceof HTMLInputElement && value !== undefined && value !== null) field.value = String(value);
      });
      setBagUnit(row, unit);
      const savedBag = row.querySelector('[data-bag-preset]');
      if (savedBag instanceof HTMLSelectElement) {
        savedBag.querySelector('[data-retained-bag-brand]')?.remove();
        const normalizedBrand = String(assignments.brand).trim().toLocaleLowerCase();
        const matchesNumber = (fieldValue, optionValue) => {
          if (fieldValue === undefined || fieldValue === null || String(fieldValue).trim() === '' || String(optionValue || '').trim() === '') return false;
          return Math.abs(Number(fieldValue) - Number(optionValue)) <= 0.01;
        };
        const match = [...savedBag.options].find((option) => option.value !== ''
          && String(option.dataset.bagBrand || '').trim().toLocaleLowerCase() === normalizedBrand
          && matchesNumber(assignments.micron, option.dataset.bagMicron)
          && matchesNumber(assignments.width, option.dataset.bagWidth)
          && matchesNumber(assignments.length, option.dataset.bagLength));
        if (match) {
          savedBag.value = match.value;
        } else if (normalizedBrand !== '') {
          const retained = document.createElement('option');
          retained.value = '__retained_brand__';
          retained.dataset.retainedBagBrand = String(assignments.brand).trim();
          retained.textContent = `${String(assignments.brand).trim()} · Custom dimensions`;
          savedBag.add(retained, 1);
          savedBag.value = retained.value;
        } else {
          savedBag.value = '';
        }
      }
    };
    const applyTemplate = (values) => {
      if (!values || typeof values !== 'object' || Array.isArray(values)) return;
      setField('start_material', pick(values, 'start_material', 'startMaterial'));
      setField('press_capacity', pick(values, 'press_capacity', 'pressCapacityTons', 'press_capacity_tons'));
      setField('humidity', pick(values, 'humidity', 'humidityPercent', 'humidity_percent'));
      const templateStartAmount = pick(values, 'start_amount', 'startAmount');
      if (templateStartAmount !== undefined && templateStartAmount !== null && startAmount instanceof HTMLInputElement) {
        startAmount.value = String(templateStartAmount);
        startAmount.readOnly = false;
        startAmount.classList.remove('is-calculated');
        startAmount.setAttribute('aria-readonly', 'false');
        manualStartAmount = startAmount.value;
        strainAmountsActive = false;
      }
      const savedStrains = pick(values, 'strains');
      if (Array.isArray(savedStrains) && savedStrains.length > 0 && strainRows instanceof HTMLElement) {
        const names = savedStrains.slice(0, 20).map((strain) => String(strain ?? '').trim()).filter((strain) => strain !== '');
        if (names.length > 0) {
          strainAmountRows?.querySelectorAll('[data-strain-amount]').forEach((field) => {
            if (field instanceof HTMLInputElement) {
              field.value = '';
              field.required = false;
            }
          });
          if (startAmount instanceof HTMLInputElement && strainAmountsActive) {
            startAmount.value = manualStartAmount;
            startAmount.readOnly = false;
            startAmount.classList.remove('is-calculated');
            startAmount.setAttribute('aria-readonly', 'false');
          }
          strainAmountsActive = false;

          let rows = [...strainRows.querySelectorAll('[data-strain-row]')];
          while (rows.length < names.length) {
            if (!(addStrainRow() instanceof HTMLElement)) break;
            rows = [...strainRows.querySelectorAll('[data-strain-row]')];
          }
          while (rows.length > names.length) rows.pop()?.remove();
          renumberStrains();
          rows = [...strainRows.querySelectorAll('[data-strain-row]')];
          rows.forEach((row, index) => {
            const input = row.querySelector('[data-combobox-input]');
            if (input instanceof HTMLInputElement) input.value = names[index] || '';
          });
          renumberStrains();

          const savedAmounts = pick(values, 'strain_amounts');
          const alignedAmounts = Array.isArray(savedAmounts) && savedAmounts.length === names.length
            ? savedAmounts.map((amount) => String(amount ?? '').trim())
            : [];
          const hasAnyAmount = alignedAmounts.some((amount) => amount !== '');
          const completeAmounts = hasAnyAmount && alignedAmounts.every((amount) => amount !== '' && Number.isFinite(Number(amount)) && Number(amount) > 0);
          const amountValues = completeAmounts ? alignedAmounts : names.map(() => '');
          strainAmountRows?.querySelectorAll('[data-strain-amount]').forEach((field, index) => {
            if (field instanceof HTMLInputElement) field.value = amountValues[index] || '';
          });
          renumberStrains();
        }
      }
      if (passRows instanceof HTMLElement) {
        const savedPasses = pick(values, 'passes');
        const templatePasses = Array.isArray(savedPasses) && savedPasses.length > 0
          ? savedPasses
          : [{
              temperature: pick(values, 'temperature', 'temperatureC', 'temperature_c'),
              pressure: pick(values, 'pressure', 'pressureBar', 'pressure_bar'),
              press_duration: pick(values, 'press_duration', 'pressDurationSeconds', 'press_duration_seconds'),
              preheat: pick(values, 'preheat', 'preheatSeconds', 'preheat_seconds'),
            }];
        passRows.replaceChildren();
        templatePasses.slice(0, maxPasses).forEach((pass) => addPassRow(pass));
        if (!passRows.querySelector('[data-pass-row]')) addPassRow({});
        renamePassRows();
      }
      const bags = pick(values, 'bags', 'micronBags', 'micron_bags');
      if (Array.isArray(bags) && bagRows instanceof HTMLElement) {
        bagRows.replaceChildren();
        bags.forEach((bag) => populateBagRow(addBagRow(), bag));
        renameBagRows();
      }
    };
    const templatePicker = batchForm.querySelector('[data-template-picker]');
    templatePicker?.addEventListener('change', () => {
      if (!(templatePicker instanceof HTMLSelectElement) || templatePicker.value === '') return;
      const option = templatePicker.selectedOptions[0];
      try { applyTemplate(JSON.parse(option?.dataset.templateValues || '{}')); }
      catch { /* Ignore malformed saved data and leave the current form unchanged. */ }
    });

    const templateToggle = batchForm.querySelector('[data-template-save-toggle]');
    const templateName = batchForm.querySelector('[data-template-name]');
    const syncTemplateName = () => {
      const enabled = templateToggle instanceof HTMLInputElement && templateToggle.checked;
      templateName?.classList.toggle('is-hidden', !enabled);
      const input = templateName?.querySelector('input');
      if (input instanceof HTMLInputElement) {
        input.required = enabled;
        if (enabled) requestAnimationFrame(() => input.focus());
      }
    };
    templateToggle?.addEventListener('change', syncTemplateName);
    syncTemplateName();

    const photoInput = batchForm.querySelector('[data-photo-input]');
    const photoDrop = batchForm.querySelector('[data-photo-drop]');
    const photoPreview = batchForm.querySelector('[data-photo-preview]');
    if (photoInput instanceof HTMLInputElement && photoDrop instanceof HTMLElement && photoPreview instanceof HTMLElement) {
      let selectedFiles = [];
      let objectUrls = [];
      const status = document.createElement('p');
      status.className = 'photo-status';
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');
      photoPreview.before(status);
      const existingCheckboxes = [...batchForm.querySelectorAll('input[name="remove_photos[]"]')];
      const existingCount = () => existingCheckboxes.filter((checkbox) => checkbox instanceof HTMLInputElement && !checkbox.checked).length;
      const syncPhotoInput = () => {
        if (typeof DataTransfer !== 'function') return;
        const transfer = new DataTransfer();
        selectedFiles.forEach((file) => transfer.items.add(file));
        photoInput.files = transfer.files;
      };
      const renderPhotos = (message = '') => {
        objectUrls.forEach((url) => URL.revokeObjectURL(url));
        objectUrls = [];
        photoPreview.replaceChildren();
        selectedFiles.forEach((file, index) => {
          const figure = document.createElement('figure');
          const image = document.createElement('img');
          const caption = document.createElement('figcaption');
          const remove = document.createElement('button');
          const url = URL.createObjectURL(file);
          objectUrls.push(url);
          image.src = url;
          image.alt = '';
          caption.textContent = file.name;
          remove.type = 'button';
          remove.className = 'photo-preview__remove';
          remove.textContent = 'Remove';
          remove.setAttribute('aria-label', `Remove ${file.name}`);
          remove.addEventListener('click', () => {
            selectedFiles.splice(index, 1);
            syncPhotoInput();
            renderPhotos();
          });
          figure.append(image, caption, remove);
          photoPreview.appendChild(figure);
        });
        const total = existingCount() + selectedFiles.length;
        status.textContent = message || (selectedFiles.length > 0 ? `${total} of 5 total photographs selected.` : '');
      };
      const addPhotos = (files) => {
        const valid = [...files].filter((file) => /^image\/(jpeg|png|webp)$/i.test(file.type));
        const invalidCount = files.length - valid.length;
        const known = new Set(selectedFiles.map((file) => `${file.name}:${file.size}:${file.lastModified}`));
        const unique = valid.filter((file) => {
          const key = `${file.name}:${file.size}:${file.lastModified}`;
          if (known.has(key)) return false;
          known.add(key);
          return true;
        });
        const available = Math.max(0, 5 - existingCount() - selectedFiles.length);
        const accepted = unique.slice(0, available);
        selectedFiles.push(...accepted);
        syncPhotoInput();
        const omitted = unique.length - accepted.length;
        const message = invalidCount > 0
          ? `${invalidCount} unsupported file${invalidCount === 1 ? '' : 's'} ignored. JPEG, PNG, and WebP are accepted.`
          : omitted > 0 ? `Only ${available} more photograph${available === 1 ? '' : 's'} could be added (5 total maximum).` : '';
        renderPhotos(message);
      };
      photoInput.addEventListener('change', () => addPhotos(photoInput.files || []));
      ['dragenter', 'dragover'].forEach((type) => photoDrop.addEventListener(type, (event) => {
        event.preventDefault();
        photoDrop.classList.add('is-dragging');
      }));
      ['dragleave', 'drop'].forEach((type) => photoDrop.addEventListener(type, (event) => {
        event.preventDefault();
        photoDrop.classList.remove('is-dragging');
      }));
      photoDrop.addEventListener('drop', (event) => addPhotos(event.dataTransfer?.files || []));
      existingCheckboxes.forEach((checkbox) => checkbox.addEventListener('change', () => {
        const allowed = Math.max(0, 5 - existingCount());
        if (selectedFiles.length > allowed) selectedFiles = selectedFiles.slice(0, allowed);
        syncPhotoInput();
        renderPhotos();
      }));
      renderPhotos();
    }

    batchForm.addEventListener('submit', (event) => {
      if (event.defaultPrevented) return;
      const button = batchForm.querySelector('[data-submit-button]');
      if (button instanceof HTMLButtonElement) {
        button.disabled = true;
        button.textContent = 'Saving…';
      }
    });
  }

  document.querySelectorAll('form[data-template-editor]').forEach((form) => {
    const strainEditor = form.querySelector('[data-template-strain-list]');
    const templateStrainRows = strainEditor?.querySelector('[data-template-strain-rows]');
    const templateStrainTemplate = strainEditor?.querySelector('[data-template-strain-template]');
    const addTemplateStrainButton = strainEditor?.querySelector('[data-template-add-strain]');
    const templateStrainEmpty = strainEditor?.querySelector('[data-template-strain-empty]');
    const templateStrainStatus = strainEditor?.querySelector('[data-template-strain-status]');
    const maxTemplateStrains = Math.max(1, Number.parseInt(strainEditor?.dataset.maxStrains || '20', 10) || 20);
    const templateWeightUnitField = form.querySelector('input[name="weight_unit"]');
    const templateWeightUnit = templateWeightUnitField instanceof HTMLInputElement ? templateWeightUnitField.value : 'g';
    const syncTemplateStrains = () => {
      if (!(templateStrainRows instanceof HTMLElement)) return;
      const rows = [...templateStrainRows.querySelectorAll('[data-template-strain-row]')];
      const amountsEnabled = rows.length > 0;
      rows.forEach((row, index) => {
        const strainNumber = index + 1;
        const label = row.querySelector('[data-template-strain-label]');
        const name = row.querySelector('[data-template-strain-name]');
        const amount = row.querySelector('[data-template-strain-amount]');
        if (label) label.textContent = String(strainNumber);
        if (name instanceof HTMLInputElement) name.setAttribute('aria-label', `Strain ${strainNumber} name`);
        if (amount instanceof HTMLInputElement) {
          amount.disabled = !amountsEnabled;
          const singleStrain = rows.length === 1;
          amount.placeholder = `${singleStrain ? 'Start amount' : 'Amount'} (${templateWeightUnit})`;
          amount.setAttribute('aria-label', `Strain ${strainNumber} ${singleStrain ? 'start amount' : 'amount'} in ${templateWeightUnit}`);
        }
        const remove = row.querySelector('[data-remove-template-strain]');
        if (remove instanceof HTMLButtonElement) remove.setAttribute('aria-label', `Remove Strain ${strainNumber}`);
      });
      const amountFields = rows.map((row) => row.querySelector('[data-template-strain-amount]'))
        .filter((field) => field instanceof HTMLInputElement);
      const hasAnyAmount = amountsEnabled && amountFields.some((field) => field.value.trim() !== '');
      amountFields.forEach((field) => { field.required = hasAnyAmount; });
      if (templateStrainStatus) {
        const values = amountFields.map((field) => Number(field.value));
        const complete = hasAnyAmount && amountFields.every((field, index) => field.value.trim() !== '' && Number.isFinite(values[index]) && values[index] > 0);
        if (complete) {
          const sum = values.reduce((total, value) => total + value, 0);
          templateStrainStatus.textContent = `Starting amount: ${Math.round((sum + Number.EPSILON) * 10000) / 10000} ${templateWeightUnit}`;
        } else if (hasAnyAmount) {
          templateStrainStatus.textContent = 'Enter an amount for every strain, or clear all amounts.';
        } else if (rows.length === 0) {
          templateStrainStatus.textContent = 'Add a strain to save a starting amount.';
        } else if (rows.length === 1) {
          templateStrainStatus.textContent = 'Starting amount is optional.';
        } else {
          templateStrainStatus.textContent = 'Amounts are optional for mixed batches.';
        }
      }
      templateStrainEmpty?.classList.toggle('is-hidden', rows.length > 0);
      if (addTemplateStrainButton instanceof HTMLButtonElement) addTemplateStrainButton.disabled = rows.length >= maxTemplateStrains;
    };
    addTemplateStrainButton?.addEventListener('click', () => {
      if (!(templateStrainRows instanceof HTMLElement) || !(templateStrainTemplate instanceof HTMLTemplateElement)) return;
      if (templateStrainRows.querySelectorAll('[data-template-strain-row]').length >= maxTemplateStrains) return;
      const fragment = templateStrainTemplate.content.cloneNode(true);
      const row = fragment.querySelector('[data-template-strain-row]');
      templateStrainRows.appendChild(fragment);
      syncTemplateStrains();
      row?.querySelector('[data-template-strain-name]')?.focus();
    });
    templateStrainRows?.addEventListener('click', (event) => {
      const button = event.target instanceof Element ? event.target.closest('[data-remove-template-strain]') : null;
      const row = button?.closest('[data-template-strain-row]');
      if (!(row instanceof HTMLElement)) return;
      const nextFocus = row.nextElementSibling || row.previousElementSibling;
      row.remove();
      syncTemplateStrains();
      const nextName = nextFocus?.querySelector('[data-template-strain-name]');
      if (nextName instanceof HTMLInputElement) nextName.focus();
      else if (addTemplateStrainButton instanceof HTMLButtonElement) addTemplateStrainButton.focus();
    });
    templateStrainRows?.addEventListener('input', syncTemplateStrains);
    syncTemplateStrains();

    const passEditor = form.querySelector('[data-template-pass-list]');
    const passRows = passEditor?.querySelector('[data-template-pass-rows]');
    const passTemplate = passEditor?.querySelector('[data-template-pass-template]');
    const addPassButton = passEditor?.querySelector('[data-template-add-pass]');
    const passStatus = passEditor?.querySelector('[data-template-pass-status]');
    const maxPasses = Math.max(1, Number.parseInt(passEditor?.dataset.maxPasses || '20', 10) || 20);
    const readPass = (row) => {
      if (!(row instanceof HTMLElement)) return {};
      const values = {};
      row.querySelectorAll('[data-template-pass-field]').forEach((field) => {
        if (field instanceof HTMLInputElement) values[field.dataset.templatePassField || ''] = field.value;
      });
      return values;
    };
    const populatePass = (row, values) => {
      if (!(row instanceof HTMLElement) || !values || typeof values !== 'object') return;
      row.querySelectorAll('[data-template-pass-field]').forEach((field) => {
        if (!(field instanceof HTMLInputElement)) return;
        field.value = String(values[field.dataset.templatePassField || ''] ?? '');
      });
    };
    const renumberPasses = () => {
      if (!(passRows instanceof HTMLElement)) return;
      const rows = [...passRows.querySelectorAll('[data-template-pass-row]')];
      rows.forEach((row, index) => {
        row.querySelectorAll('[name]').forEach((field) => {
          const name = field.getAttribute('name');
          if (name) field.setAttribute('name', name.replace(/passes\[(?:__INDEX__|\d+)\]/, `passes[${index}]`));
        });
        const passNumber = index + 1;
        const label = row.querySelector('[data-template-pass-label]');
        if (label) label.textContent = String(passNumber);
        row.querySelectorAll('[data-template-pass-field]').forEach((field) => {
          if (!(field instanceof HTMLInputElement)) return;
          const descriptions = {
            temperature: 'temperature',
            pressure: 'pressure',
            press_duration: 'duration in seconds',
            preheat: 'preheat in seconds',
          };
          field.setAttribute('aria-label', `Pass ${passNumber} ${descriptions[field.dataset.templatePassField || ''] || 'setting'}`);
        });
        const remove = row.querySelector('[data-remove-template-pass]');
        if (remove instanceof HTMLButtonElement) {
          remove.disabled = rows.length === 1;
          remove.setAttribute('aria-label', `Remove Pass ${passNumber}`);
        }
      });
      if (addPassButton instanceof HTMLButtonElement) addPassButton.disabled = rows.length >= maxPasses;
      if (passStatus) passStatus.textContent = `${rows.length} of ${maxPasses} passes`;
    };
    const addTemplatePass = (values = null) => {
      if (!(passRows instanceof HTMLElement) || !(passTemplate instanceof HTMLTemplateElement)) return null;
      if (passRows.querySelectorAll('[data-template-pass-row]').length >= maxPasses) return null;
      const fragment = passTemplate.content.cloneNode(true);
      const row = fragment.querySelector('[data-template-pass-row]');
      passRows.appendChild(fragment);
      renumberPasses();
      if (row instanceof HTMLElement && values) populatePass(row, values);
      return row instanceof HTMLElement ? row : null;
    };
    addPassButton?.addEventListener('click', () => {
      if (!(passRows instanceof HTMLElement)) return;
      const rows = [...passRows.querySelectorAll('[data-template-pass-row]')];
      const previous = rows.length > 0 ? readPass(rows[rows.length - 1]) : {};
      addTemplatePass(previous)?.querySelector('[data-template-pass-field="temperature"]')?.focus();
    });
    passRows?.addEventListener('click', (event) => {
      const button = event.target instanceof Element ? event.target.closest('[data-remove-template-pass]') : null;
      const row = button?.closest('[data-template-pass-row]');
      if (!(row instanceof HTMLElement) || !(passRows instanceof HTMLElement)) return;
      if (passRows.querySelectorAll('[data-template-pass-row]').length <= 1) return;
      const nextFocus = row.nextElementSibling || row.previousElementSibling;
      row.remove();
      renumberPasses();
      nextFocus?.querySelector('[data-template-pass-field="temperature"]')?.focus();
    });
    if (passRows instanceof HTMLElement && !passRows.querySelector('[data-template-pass-row]')) addTemplatePass({});
    renumberPasses();

    const bagEditor = form.querySelector('[data-template-bag-list]');
    const rowsContainer = bagEditor?.querySelector('[data-template-bag-rows]');
    const rowTemplate = bagEditor?.querySelector('[data-template-bag-template]');
    const empty = bagEditor?.querySelector('[data-template-bag-empty]');
    const renumberRows = () => {
      if (!(rowsContainer instanceof HTMLElement)) return;
      const rows = [...rowsContainer.querySelectorAll('[data-template-bag-row]')];
      rows.forEach((row, index) => {
        row.querySelectorAll('[name]').forEach((field) => {
          const name = field.getAttribute('name');
          if (name) field.setAttribute('name', name.replace(/bags\[(?:__INDEX__|\d+)\]/, `bags[${index}]`));
        });
        const layer = index + 1;
        const label = row.querySelector('[data-template-layer-label]');
        const input = row.querySelector('[data-template-layer-input]');
        if (label) label.textContent = String(layer);
        if (input instanceof HTMLInputElement) input.value = String(layer);
      });
      empty?.classList.toggle('is-hidden', rows.length > 0);
    };
    bagEditor?.querySelector('[data-template-add-bag]')?.addEventListener('click', () => {
      if (!(rowsContainer instanceof HTMLElement) || !(rowTemplate instanceof HTMLTemplateElement)) return;
      const fragment = rowTemplate.content.cloneNode(true);
      const row = fragment.querySelector('[data-template-bag-row]');
      rowsContainer.appendChild(fragment);
      renumberRows();
      row?.querySelector('input:not([type="hidden"])')?.focus();
    });
    rowsContainer?.addEventListener('click', (event) => {
      const button = event.target instanceof Element ? event.target.closest('[data-remove-template-bag]') : null;
      const row = button?.closest('[data-template-bag-row]');
      if (!(row instanceof HTMLElement)) return;
      row.remove();
      renumberRows();
    });
    renumberRows();
  });

  const oidcForm = document.querySelector('form[action="/settings/authentication/oidc"]');
  if (oidcForm instanceof HTMLFormElement) {
    const providerRadios = [...oidcForm.querySelectorAll('input[name="provider_type"]')];
    const entraFields = oidcForm.querySelector('.provider-fields--entra');
    const genericFields = oidcForm.querySelector('.provider-fields--generic');
    const tenantInput = oidcForm.querySelector('input[name="tenant_id"]');
    const issuerInput = oidcForm.querySelector('input[name="issuer"]');
    const tokenMethod = oidcForm.querySelector('select[name="token_auth_method"]');
    const syncProviderFields = () => {
      const selected = providerRadios.find((radio) => radio instanceof HTMLInputElement && radio.checked);
      const entra = !(selected instanceof HTMLInputElement) || selected.value === 'entra';
      entraFields?.classList.toggle('is-hidden', !entra);
      genericFields?.classList.toggle('is-hidden', entra);
      if (tenantInput instanceof HTMLInputElement) tenantInput.required = entra;
      if (issuerInput instanceof HTMLInputElement) issuerInput.required = !entra;
      if (tokenMethod instanceof HTMLSelectElement && entra) tokenMethod.value = 'client_secret_post';
    };
    providerRadios.forEach((radio) => radio.addEventListener('change', syncProviderFields));
    syncProviderFields();
  }

  document.querySelectorAll('[data-copy-target]').forEach((button) => {
    button.addEventListener('click', async () => {
      if (!(button instanceof HTMLButtonElement)) return;
      const target = document.getElementById(button.dataset.copyTarget || '');
      if (!(target instanceof HTMLInputElement) || target.value === '') return;
      const original = button.textContent || 'Copy';
      try {
        if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(target.value);
        else {
          target.focus();
          target.select();
          document.execCommand('copy');
        }
        button.textContent = 'Copied';
      } catch {
        target.focus();
        target.select();
        button.textContent = 'Select';
      }
      window.setTimeout(() => { button.textContent = original; }, 1800);
    });
  });

  document.querySelectorAll('[data-sortable-comparison]').forEach((table) => {
    if (!(table instanceof HTMLTableElement)) return;
    const body = table.tBodies.item(0);
    if (!(body instanceof HTMLTableSectionElement)) return;
    const buttons = [...table.querySelectorAll('[data-sort-column]')];
    const status = table.parentElement?.querySelector('[data-comparison-sort-status]');
    const summary = table.closest('.comparison-panel')?.querySelector('[data-comparison-sort-summary]');
    const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });

    buttons.forEach((button) => button.addEventListener('click', () => {
      if (!(button instanceof HTMLButtonElement)) return;
      const column = Number.parseInt(button.dataset.sortColumn || '', 10);
      if (!Number.isInteger(column)) return;
      const type = button.dataset.sortType === 'numeric' ? 'numeric' : 'string';
      const previousColumn = Number.parseInt(table.dataset.sortColumn || '', 10);
      const previousDirection = table.dataset.sortDirection === 'desc' ? 'desc' : 'asc';
      const direction = previousColumn === column && previousDirection === 'asc' ? 'desc' : 'asc';
      const multiplier = direction === 'asc' ? 1 : -1;

      const rows = [...body.rows];
      rows.sort((left, right) => {
        const leftCell = left.cells.item(column);
        const rightCell = right.cells.item(column);
        if (!(leftCell instanceof HTMLTableCellElement) || !(rightCell instanceof HTMLTableCellElement)) return 0;
        const leftMissing = leftCell.dataset.sortMissing === 'true';
        const rightMissing = rightCell.dataset.sortMissing === 'true';
        const originalOrder = Number(left.dataset.sortOriginal || 0) - Number(right.dataset.sortOriginal || 0);
        if (leftMissing && rightMissing) return originalOrder;
        if (leftMissing) return 1;
        if (rightMissing) return -1;

        const leftValue = leftCell.dataset.sortValue || '';
        const rightValue = rightCell.dataset.sortValue || '';
        const comparison = type === 'numeric'
          ? Number(leftValue) - Number(rightValue)
          : collator.compare(leftValue, rightValue);
        return comparison === 0 ? originalOrder : comparison * multiplier;
      }).forEach((row) => body.appendChild(row));

      table.dataset.sortColumn = String(column);
      table.dataset.sortDirection = direction;
      table.querySelectorAll('thead th[aria-sort]').forEach((heading) => heading.setAttribute('aria-sort', 'none'));
      button.closest('th')?.setAttribute('aria-sort', direction === 'asc' ? 'ascending' : 'descending');
      const label = button.textContent?.trim() || 'Column';
      const directionLabel = direction === 'asc' ? 'ascending' : 'descending';
      if (status) status.textContent = `${label} sorted ${directionLabel}. Missing values are last.`;
      if (summary) summary.textContent = `${label} ${direction === 'asc' ? '↑' : '↓'}`;
    }));
  });

  const batchBrowser = document.querySelector('[data-batch-browser]');
  if (batchBrowser instanceof HTMLElement) {
    const search = batchBrowser.querySelector('[data-batch-search]');
    const sort = batchBrowser.querySelector('[data-batch-sort]');
    const grid = batchBrowser.querySelector('[data-batch-grid]');
    const count = batchBrowser.querySelector('[data-visible-count]');
    const noun = batchBrowser.querySelector('[data-visible-noun]');
    const empty = batchBrowser.querySelector('[data-filter-empty]');
    const applyBatchFilters = () => {
      if (!(grid instanceof HTMLElement)) return;
      const query = search instanceof HTMLInputElement ? search.value.trim().toLocaleLowerCase() : '';
      const mode = sort instanceof HTMLSelectElement ? sort.value : 'date';
      const cards = [...grid.querySelectorAll('[data-batch-card]')];
      cards.sort((left, right) => {
        if (mode === 'strain') return (left.dataset.strain || '').localeCompare(right.dataset.strain || '');
        if (mode === 'yield') return Number(right.dataset.yield || 0) - Number(left.dataset.yield || 0);
        if (mode === 'amount') return Number(right.dataset.amount || 0) - Number(left.dataset.amount || 0);
        return (Date.parse(right.dataset.date || '') || 0) - (Date.parse(left.dataset.date || '') || 0);
      }).forEach((card) => grid.appendChild(card));
      let visible = 0;
      cards.forEach((card) => {
        const matches = query === '' || (card.dataset.search || '').toLocaleLowerCase().includes(query);
        card.hidden = !matches;
        if (matches) visible += 1;
      });
      if (count) count.textContent = String(visible);
      if (noun) noun.textContent = visible === 1 ? 'batch' : 'batches';
      empty?.classList.toggle('is-hidden', visible > 0);
    };
    search?.addEventListener('input', applyBatchFilters);
    sort?.addEventListener('change', applyBatchFilters);
    applyBatchFilters();
  }
})();
