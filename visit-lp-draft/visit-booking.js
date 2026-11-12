/* 京都市ページの申込UI。API設定がない環境ではプレビュー送信のみで、実際の受付は行いません。 */
(function () {
  'use strict';

  const widget = document.querySelector('[data-visit-booking-widget]');
  if (!widget) return;

  const isLocalPreview = ['localhost', '127.0.0.1', '::1'].includes(window.location.hostname);
  const unavailable = widget.querySelector('[data-booking-unavailable]');
  const demoNotice = widget.querySelector('[data-booking-demo]');
  const liveMessage = widget.querySelector('[data-booking-message]');
  const bookingConfig = window.VISIT_BOOKING_CONFIG || {
    slots: ['10:00〜12:00', '12:00〜14:00', '14:00〜16:00', '16:00〜18:00', '18:00〜20:00'],
    multiSlotBonusEnabled: true,
    multiSlotBonusPercent: 1,
    applicationSubmitEndpoint: '',
    applicationStatusEndpoint: '',
    applicationConfirmationPageUrl: '/visit/kyoto-city/application-pending/'
  };
  const applicationFlowReady = Boolean(bookingConfig.applicationSubmitEndpoint && bookingConfig.applicationStatusEndpoint);
  const bonusInfo = widget.querySelector('[data-bonus-info]');
  const bonusMessage = widget.querySelector('[data-bonus-message]');
  const bonusCalculation = widget.querySelector('[data-bonus-calculation]');
  const timeSelectionPrompt = widget.querySelector('[data-time-selection-prompt]');
  const timeWindowNote = widget.querySelector('[data-time-window-note]');

  if (!isLocalPreview && !applicationFlowReady) {
    unavailable.hidden = false;
    unavailable.innerHTML = '<strong>お申し込み受付の準備中です</strong><span>仮申込の保存と確認メール送信は未接続のため、現在は受付できません。</span>';
    widget.querySelectorAll('input, select, button').forEach((control) => { control.disabled = true; });
    widget.querySelector('[data-submit-note]').textContent = '仮申込API・メール確認機能の接続後に受付を開始します。';
    return;
  }

  demoNotice.hidden = false;
  const state = { step: 1, area: '', handoff: '', consent: false, day: null, times: [] };
  const monthLabel = widget.querySelector('[data-calendar-month]');
  const calendarDays = widget.querySelector('[data-calendar-days]');
  const timeSlots = widget.querySelector('[data-time-slots]');
  const consentBox = widget.querySelector('[name="contactless-consent"]');
  const consentAt = widget.querySelector('[name="contactless-consented-at"]');
  const form = widget.querySelector('[data-application-form]');
  const formResult = widget.querySelector('[data-application-result]');
  const applicationWaiting = widget.querySelector('[data-application-waiting]');
  const applicationWaitingStatus = widget.querySelector('[data-application-waiting-status]');
  const terms = widget.querySelector('[data-contactless-terms]');
  const pickupPlaceDetails = widget.querySelector('[data-contactless-pickup-details]');
  const pickupPlaceDetailsInput = widget.querySelector('[data-pickup-place-details]');
  const nameplateMismatch = widget.querySelector('[data-nameplate-mismatch]');
  const nameplateMismatchInput = form.elements.nameplate_name;
  const pickupMapElement = widget.querySelector('[data-pickup-map]');
  const pickupMapStatus = widget.querySelector('[data-pickup-map-status]');
  const pickupAddressSearchButton = widget.querySelector('[data-map-address-search]');
  const pickupLatitude = widget.querySelector('[data-pickup-latitude]');
  const pickupLongitude = widget.querySelector('[data-pickup-longitude]');
  const now = new Date();
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const earliest = new Date(today);
  earliest.setDate(earliest.getDate() + 1);
  let displayedMonth = new Date(today.getFullYear(), today.getMonth(), 1);

  // 以下の空き枠は操作イメージ確認用の仮データで、予約には使われません。
  const demoSlots = Array.isArray(bookingConfig.slots) && bookingConfig.slots.length
    ? bookingConfig.slots
    : ['10:00〜12:00', '12:00〜14:00', '14:00〜16:00', '16:00〜18:00', '18:00〜20:00'];
  const bonusEnabled = bookingConfig.multiSlotBonusEnabled !== false;
  const bonusPercent = Number(bookingConfig.multiSlotBonusPercent) > 0 ? Number(bookingConfig.multiSlotBonusPercent) : 1;
  function setStep(step) {
    state.step = step;
    widget.querySelectorAll('[data-step]').forEach((panel) => {
      panel.hidden = Number(panel.dataset.step) !== step;
    });
    widget.querySelectorAll('[data-progress]').forEach((item) => {
      const number = Number(item.dataset.progress);
      item.classList.toggle('is-current', number === step);
      item.classList.toggle('is-done', number < step);
      if (number === step) item.setAttribute('aria-current', 'step');
      else item.removeAttribute('aria-current');
    });
    liveMessage.textContent = `ステップ${step}を表示しています。`;
    if (step === 6) {
      initializePickupMap();
      geocodePickupAddress();
      if (pickupMap) window.requestAnimationFrame(() => pickupMap.invalidateSize());
    }
  }

  function scrollToStepTop(step) {
    const stepHeading = widget.querySelector(`[data-step="${step}"] .visit-booking-step__heading`);
    if (!stepHeading) return;
    const title = stepHeading.querySelector('h3');
    if (title) {
      title.setAttribute('tabindex', '-1');
      title.focus({ preventScroll: true });
    }
    const behavior = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
    stepHeading.scrollIntoView({ behavior, block: 'start' });
  }

  let pickupMap = null;
  let pickupMarker = null;
  let lastGeocodedAddress = '';
  let geocodeRequestId = 0;
  function initializePickupMap() {
    if (pickupMap || !pickupMapElement) return;
    if (!window.L) {
      pickupMapStatus.textContent = '地図を読み込めませんでした。通信を確認してページを再読み込みしてください。';
      pickupMapStatus.classList.add('is-error');
      return;
    }
    pickupMap = window.L.map(pickupMapElement, { scrollWheelZoom: false, tap: true })
      .setView([35.0116, 135.7681], 13);
    window.L.tileLayer(pickupMapElement.dataset.tileUrl, {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(pickupMap);

    const updatePickupCoordinates = (point) => {
      pickupLatitude.value = point.lat.toFixed(6);
      pickupLongitude.value = point.lng.toFixed(6);
      pickupMapStatus.textContent = `集荷場所を指定しました（緯度 ${pickupLatitude.value} / 経度 ${pickupLongitude.value}）。ピンをドラッグして調整できます。`;
      pickupMapStatus.classList.remove('is-error');
    };
    const placePickupMarker = (point) => {
      if (!pickupMarker) {
        pickupMarker = window.L.marker(point, { draggable: true, keyboard: true, title: '集荷場所。ドラッグして位置を調整できます' }).addTo(pickupMap);
        pickupMarker.on('dragend', () => updatePickupCoordinates(pickupMarker.getLatLng()));
      } else {
        pickupMarker.setLatLng(point);
      }
      updatePickupCoordinates(point);
    };
    pickupMap.on('click', (event) => placePickupMarker(event.latlng));
    pickupAddressSearchButton.addEventListener('click', geocodePickupAddress);
    pickupMap.whenReady(() => {
      pickupMap.invalidateSize();
      pickupMapStatus.textContent = '地図をクリックして集荷場所にピンを置いてください。';
    });
  }

  async function geocodePickupAddress() {
    if (!pickupMap) return;
    const address = [form.elements.prefecture.value, form.elements.city.value, form.elements.street.value]
      .map((part) => part.trim())
      .filter(Boolean)
      .join('');
    if (!address) {
      pickupMapStatus.textContent = 'お客様情報の住所を入力してから、地図の位置を設定してください。';
      pickupMapStatus.classList.add('is-error');
      return;
    }
    if (address === lastGeocodedAddress) return;
    lastGeocodedAddress = address;
    const requestId = ++geocodeRequestId;
    if (pickupMarker) {
      pickupMap.removeLayer(pickupMarker);
      pickupMarker = null;
    }
    pickupLatitude.value = '';
    pickupLongitude.value = '';
    pickupMapStatus.textContent = '入力住所付近を検索しています…';
    pickupMapStatus.classList.remove('is-error');
    try {
      const endpoint = `https://msearch.gsi.go.jp/address-search/AddressSearch?q=${encodeURIComponent(address)}`;
      const response = await fetch(endpoint, { headers: { Accept: 'application/json' } });
      if (!response.ok) throw new Error('住所を検索できませんでした。');
      const result = await response.json();
      const feature = Array.isArray(result) ? result[0] : (result.features || [result])[0];
      const coordinates = feature?.geometry?.coordinates;
      const longitude = Number(coordinates?.[0]);
      const latitude = Number(coordinates?.[1]);
      if (requestId !== geocodeRequestId) return;
      if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) throw new Error('住所の位置が見つかりませんでした。');
      if (pickupMarker) return;
      pickupMap.setView([latitude, longitude], 17);
      pickupMapStatus.textContent = '入力住所付近へ地図を移動しました。検索位置は目安です。地図をクリックして集荷場所にピンを置いてください。';
    } catch (error) {
      if (requestId !== geocodeRequestId) return;
      lastGeocodedAddress = '';
      pickupMapStatus.textContent = '住所を地図で検索できませんでした。地図を移動して集荷場所にピンを置いてください。';
      pickupMapStatus.classList.add('is-error');
    }
  }

  function updateContactlessPickupFields() {
    const isContactless = state.handoff === 'contactless';
    pickupPlaceDetails.hidden = !isContactless;
    pickupPlaceDetailsInput.disabled = !isContactless;
    pickupPlaceDetailsInput.required = isContactless;
    if (!isContactless) pickupPlaceDetailsInput.value = '';
  }

  updateContactlessPickupFields();

  function updateNameplateMismatchField() {
    const noNameplate = form.querySelector('[name="nameplate"]:checked')?.value === 'no';
    nameplateMismatch.hidden = !noNameplate;
    if (!noNameplate) nameplateMismatchInput.value = '';
  }

  function validateFormStep(step) {
    const panel = widget.querySelector(`[data-step="${step}"]`);
    if (!panel) return true;
    const firstInvalid = [...panel.querySelectorAll('input, select, textarea')]
      .find((control) => !control.disabled && control.type !== 'hidden' && !control.classList.contains('visit-honeypot') && !control.checkValidity());
    if (!firstInvalid) return true;
    setStep(step);
    firstInvalid.reportValidity();
    return false;
  }

  const normalizeKanaForMatch = (value) => String(value || '')
    .normalize('NFKC')
    .replace(/[ぁ-ゖ]/g, (character) => String.fromCharCode(character.charCodeAt(0) + 0x60))
    .replace(/[\s\u00a0\u3000]/gu, '');
  function accountHolderMatchesApplicant() {
    const applicantName = normalizeKanaForMatch(`${form.elements.last_name_kana.value}${form.elements.first_name_kana.value}`);
    const accountHolder = normalizeKanaForMatch(form.elements.account_holder_kana.value);
    return Boolean(applicantName && accountHolder && applicantName === accountHolder);
  }
  function validateAccountHolder() {
    const field = form.elements.account_holder_kana;
    field.setCustomValidity(accountHolderMatchesApplicant() ? '' : '口座名義（カナ）は、お客様情報のセイ・メイと一致するように入力してください。');
    if (field.validationMessage) {
      setStep(5);
      field.reportValidity();
      return false;
    }
    return true;
  }

  const bankDirectory = (() => {
    const bankIndex = widget.querySelector('#visit-bank-index');
    const bankSelect = widget.querySelector('#visit-bank-select');
    const bankName = widget.querySelector('[data-bank-name]');
    const branchIndex = widget.querySelector('#visit-branch-index');
    const branchSelect = widget.querySelector('#visit-branch-select');
    const branchName = widget.querySelector('[data-branch-name]');
    const message = widget.querySelector('[data-bank-message]');
    if (!bankIndex || !bankSelect || !branchSelect) return null;

    const initials = 'あいうえおかきくけこさしすせそたちつてとなにぬねのはひふへほまみむめもやゆよらりるれろわ'.split('');
    const bankUrl = 'https://zengin-code.github.io/api/banks.json';
    const branchUrl = (code) => `https://zengin-code.github.io/api/branches/${encodeURIComponent(code)}.json`;
    let banks = null;
    let branches = null;
    let loadingBanks = null;

    const toHira = (value) => (value || '').replace(/[ァ-ン]/g, (c) => String.fromCharCode(c.charCodeAt(0) - 0x60));
    const normalizeInitial = (char) => ({が:'か',ぎ:'き',ぐ:'く',げ:'け',ご:'こ',ざ:'さ',じ:'し',ず:'す',ぜ:'せ',ぞ:'そ',だ:'た',ぢ:'ち',づ:'つ',で:'て',ど:'と',ば:'は',び:'ひ',ぶ:'ふ',べ:'へ',ぼ:'ほ',ぱ:'は',ぴ:'ひ',ぷ:'ふ',ぺ:'へ',ぽ:'ほ',ぁ:'あ',ぃ:'い',ぅ:'う',ぇ:'え',ぉ:'お',ゃ:'や',ゅ:'ゆ',ょ:'よ',っ:'つ',ゔ:'う'})[char] || char;
    const firstChar = (record) => normalizeInitial(toHira(record.hira || record.kana || '').trim()[0] || '');
    const normalizeBankName = (name) => (name || '').replace(/信金/g, '信用金庫').replace(/信組/g, '信用組合');
    const makeIndex = (root, allowed = new Set(initials)) => {
      root.replaceChildren();
      initials.filter((char) => allowed.has(char)).forEach((char) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = char;
        button.dataset.char = char;
        button.setAttribute('aria-label', `${char}行から選ぶ`);
        root.append(button);
      });
    };
    const makeOptions = (select, records, placeholder) => {
      select.replaceChildren(new Option(placeholder, ''));
      Object.entries(records || {}).sort((a, b) => normalizeBankName(a[1].name).localeCompare(normalizeBankName(b[1].name), 'ja')).forEach(([code, record]) => {
        const option = new Option(`${normalizeBankName(record.name)}（${code}）`, code);
        option.dataset.name = normalizeBankName(record.name);
        select.add(option);
      });
    };
    const loadBanks = async () => {
      if (banks) return banks;
      if (!loadingBanks) loadingBanks = fetch(bankUrl, { cache: 'force-cache' }).then((response) => {
        if (!response.ok) throw new Error('銀行一覧を取得できませんでした。');
        return response.json();
      }).then((data) => { banks = data; return banks; });
      return loadingBanks;
    };
    const selectByInitial = (records, char) => Object.fromEntries(Object.entries(records || {}).filter(([, record]) => firstChar(record) === char));
    const resetBranch = (text) => {
      branches = null;
      branchIndex.replaceChildren();
      branchSelect.disabled = true;
      makeOptions(branchSelect, {}, text || '先に銀行を選んでください');
      branchName.value = '';
      widget.querySelector('[name="branch_code"]').value = '';
    };
    const setStatus = (text, isError = false) => {
      message.textContent = text;
      message.classList.toggle('is-error', isError);
    };

    makeIndex(bankIndex);
    bankIndex.addEventListener('click', async (event) => {
      const button = event.target.closest('button[data-char]');
      if (!button) return;
      bankIndex.querySelectorAll('button').forEach((item) => item.classList.toggle('is-active', item === button));
      bankSelect.replaceChildren(new Option('銀行一覧を読み込んでいます…', ''));
      setStatus('銀行一覧を読み込んでいます。');
      try {
        await loadBanks();
        makeOptions(bankSelect, selectByInitial(banks, button.dataset.char), '銀行を選択してください');
        bankSelect.focus();
        setStatus('五十音に該当する銀行を選択してください。');
      } catch (error) {
        setStatus('銀行一覧を読み込めませんでした。通信を確認して再度お試しください。', true);
        makeOptions(bankSelect, {}, '銀行一覧を取得できません');
      }
      bankName.value = '';
      resetBranch('先に銀行を選んでください');
    });

    bankSelect.addEventListener('change', async () => {
      const selected = bankSelect.selectedOptions[0];
      bankName.value = selected?.dataset.name || '';
      if (!bankSelect.value) { resetBranch('先に銀行を選んでください'); return; }
      resetBranch('支店一覧を読み込んでいます…');
      setStatus('支店一覧を読み込んでいます。');
      try {
        const response = await fetch(branchUrl(bankSelect.value), { cache: 'force-cache' });
        if (!response.ok) throw new Error('支店一覧を取得できませんでした。');
        branches = await response.json();
        const available = new Set(Object.values(branches).map(firstChar).filter(Boolean));
        makeIndex(branchIndex, available);
        branchSelect.disabled = false;
        makeOptions(branchSelect, {}, '支店の五十音を選んでください');
        setStatus('支店の五十音を選択してください。');
      } catch (error) {
        resetBranch('支店一覧を取得できません');
        setStatus('支店一覧を読み込めませんでした。銀行を選び直してください。', true);
      }
    });

    branchIndex.addEventListener('click', (event) => {
      const button = event.target.closest('button[data-char]');
      if (!button || !branches) return;
      branchIndex.querySelectorAll('button').forEach((item) => item.classList.toggle('is-active', item === button));
      makeOptions(branchSelect, selectByInitial(branches, button.dataset.char), '支店を選択してください');
      branchSelect.disabled = false;
      branchName.value = '';
    });
    branchSelect.addEventListener('change', () => {
      branchName.value = branchSelect.selectedOptions[0]?.dataset.name || '';
    });

    return { init: () => setStatus('五十音を選ぶと銀行一覧が表示されます。') };
  })();

  function renderCalendar() {
    const year = displayedMonth.getFullYear();
    const month = displayedMonth.getMonth();
    monthLabel.textContent = `${year}年${month + 1}月`;
    calendarDays.replaceChildren();

    const firstWeekday = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    for (let i = 0; i < firstWeekday; i += 1) {
      const blank = document.createElement('span');
      blank.setAttribute('aria-hidden', 'true');
      calendarDays.append(blank);
    }

    for (let dayNumber = 1; dayNumber <= daysInMonth; dayNumber += 1) {
      const date = new Date(year, month, dayNumber);
      const button = document.createElement('button');
      const isAvailable = date >= earliest && [1, 3, 5].includes(date.getDay());
      button.type = 'button';
      const dayLabel = document.createElement('span');
      dayLabel.textContent = String(dayNumber);
      const statusLabel = document.createElement('small');
      statusLabel.textContent = date < earliest ? '－' : (isAvailable ? '○' : '×');
      button.append(dayLabel, statusLabel);
      button.disabled = !isAvailable;
      button.classList.toggle('is-available', isAvailable);
      button.classList.toggle('is-selected', Boolean(state.day && date.toDateString() === state.day.toDateString()));
      button.setAttribute('aria-label', `${year}年${month + 1}月${dayNumber}日${isAvailable ? ' 画面確認用の仮枠あり' : (date < earliest ? ' 受付対象外' : ' 予約不可')}`);
      if (isAvailable) {
        button.addEventListener('click', () => {
          state.day = date;
          state.times = [];
          renderCalendar();
          renderSlots();
          widget.querySelector('[data-next-step="4"]').disabled = true;
        });
      }
      calendarDays.append(button);
    }
  }

  function renderSlots() {
    timeSlots.replaceChildren();
    bonusInfo.hidden = !bonusEnabled || !state.day;
    timeSelectionPrompt.hidden = !state.day;
    timeWindowNote.hidden = !state.day;
    bonusMessage.textContent = `2枠目以降、追加1枠ごとに査定額 +${bonusPercent}%（実査定システム未接続）`;
    const bonusTotal = Math.max(0, state.times.length - 1) * bonusPercent;
    bonusCalculation.textContent = `選択中の特典：+${Number(bonusTotal.toFixed(2))}%（画面確認用）`;
    if (!state.day) {
      const message = document.createElement('p');
      message.textContent = 'カレンダーから回収希望日を選択してください。';
      timeSlots.append(message);
      return;
    }
    demoSlots.forEach((slot, index) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = `${slot} · 仮枠`;
      const isSelected = state.times.includes(slot);
      button.classList.toggle('is-selected', isSelected);
      button.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
      if (index === 1 && state.day.getDate() % 2 === 0) {
        button.disabled = true;
        button.textContent = `${slot} · 受付対象外`;
      } else {
        button.addEventListener('click', () => {
          state.times = isSelected
            ? state.times.filter((selectedSlot) => selectedSlot !== slot)
            : [...state.times, slot];
          renderSlots();
          widget.querySelector('[data-next-step="4"]').disabled = state.times.length === 0;
        });
      }
      timeSlots.append(button);
    });
  }

  widget.querySelectorAll('[name="visit-area"]').forEach((input) => {
    input.addEventListener('change', () => {
      state.area = input.value;
      widget.querySelector('[data-next-step="2"]').disabled = false;
    });
  });

  widget.querySelectorAll('[name="visit-handoff"]').forEach((input) => {
    input.addEventListener('change', () => {
      state.handoff = input.value;
      const isContactless = state.handoff === 'contactless';
      terms.hidden = !isContactless;
      updateContactlessPickupFields();
      consentBox.required = isContactless;
      if (!isContactless) {
        consentBox.checked = false;
        consentAt.value = '';
        state.consent = false;
      }
      widget.querySelector('[data-next-step="3"]').disabled = isContactless;
    });
  });

  widget.querySelectorAll('[name="nameplate"]').forEach((input) => {
    input.addEventListener('change', updateNameplateMismatchField);
  });

  consentBox.addEventListener('change', () => {
    state.consent = consentBox.checked;
    consentAt.value = consentBox.checked ? new Date().toISOString() : '';
    widget.querySelector('[data-next-step="3"]').disabled = state.handoff === 'contactless' && !state.consent;
  });

  widget.querySelectorAll('[data-next-step]').forEach((button) => {
    button.addEventListener('click', () => {
      const next = Number(button.dataset.nextStep);
      if (next === 3 && (!state.handoff || (state.handoff === 'contactless' && !state.consent))) return;
      if ((next === 5 && !validateFormStep(4)) || (next === 6 && !validateFormStep(5))) return;
      if (next === 6 && !validateAccountHolder()) return;
      if (next === 4) {
        if (!state.day || state.times.length === 0) return;
        widget.querySelector('[data-summary-area]').textContent = `京都市${state.area}`;
        widget.querySelector('[data-summary-handoff]').textContent = state.handoff === 'face' ? '対面' : '非対面';
        widget.querySelector('[data-summary-date]').textContent = `${state.day.getFullYear()}年${state.day.getMonth() + 1}月${state.day.getDate()}日`;
        widget.querySelector('[data-summary-time]').textContent = state.times.join('・');
        const summaryBonus = widget.querySelector('[data-summary-bonus]');
        summaryBonus.textContent = !bonusEnabled
          ? '無効'
          : (state.times.length > 1 ? `+${Number((Math.max(0, state.times.length - 1) * bonusPercent).toFixed(2))}%（画面確認用）` : '対象外（1枠選択）');
        widget.querySelector('[data-form-area]').value = `京都市${state.area}`;
        widget.querySelector('[data-form-handoff]').value = state.handoff;
        widget.querySelector('[data-form-date]').value = `${state.day.getFullYear()}-${String(state.day.getMonth() + 1).padStart(2, '0')}-${String(state.day.getDate()).padStart(2, '0')}`;
        widget.querySelector('[data-form-times]').value = state.times.join('|');
        widget.querySelector('[data-form-consented-at]').value = state.handoff === 'contactless' ? consentAt.value : '';
        const cityField = widget.querySelector('[data-city]');
        cityField.value = `京都市${state.area}`;
      }
      setStep(next);
      scrollToStepTop(next);
      if (next === 5) bankDirectory?.init();
    });
  });

  widget.querySelectorAll('[data-previous-step]').forEach((button) => {
    button.addEventListener('click', () => {
      const previousStep = Number(button.dataset.previousStep);
      setStep(previousStep);
      scrollToStepTop(previousStep);
    });
  });

  widget.querySelector('[data-calendar-prev]').addEventListener('click', () => {
    const previous = new Date(displayedMonth.getFullYear(), displayedMonth.getMonth() - 1, 1);
    if (previous < new Date(today.getFullYear(), today.getMonth(), 1)) return;
    displayedMonth = previous;
    renderCalendar();
    renderSlots();
  });
  widget.querySelector('[data-calendar-next]').addEventListener('click', () => {
    displayedMonth = new Date(displayedMonth.getFullYear(), displayedMonth.getMonth() + 1, 1);
    renderCalendar();
    renderSlots();
  });
  const isKanaOnly = (value) => /^[ぁ-ゖァ-ヺー\s　]+$/.test(value);
  const normalizeKana = (value) => value.replace(/[ぁ-ゖ]/g, (character) => String.fromCharCode(character.charCodeAt(0) + 0x60)).replace(/[\s　]+/g, '').trim();
  form.querySelectorAll('[data-name-kanji]').forEach((nameInput) => {
    const kanaInput = form.elements[nameInput.dataset.nameKanji];
    let kanaCandidate = '';
    nameInput.addEventListener('compositionupdate', (event) => {
      if (isKanaOnly(event.data || '')) kanaCandidate = normalizeKana(event.data || '');
    });
    nameInput.addEventListener('compositionend', () => {
      if (kanaCandidate) kanaInput.value = kanaCandidate;
      kanaCandidate = '';
    });
    nameInput.addEventListener('input', () => {
      if (!nameInput.value.trim()) { kanaInput.value = ''; kanaCandidate = ''; }
    });
  });
  form.querySelectorAll('[data-kana]').forEach((input) => {
    input.addEventListener('blur', () => {
      input.value = normalizeKana(input.value);
    });
    input.addEventListener('input', () => form.elements.account_holder_kana.setCustomValidity(''));
  });
  form.elements.phone.addEventListener('blur', () => {
    form.elements.phone.value = form.elements.phone.value
      .replace(/[０-９]/g, (character) => String.fromCharCode(character.charCodeAt(0) - 0xFEE0))
      .replace(/[\s()-]/g, '');
  });
  form.elements.postal_code.addEventListener('blur', () => {
    form.elements.postal_code.value = form.elements.postal_code.value
      .replace(/[０-９]/g, (character) => String.fromCharCode(character.charCodeAt(0) - 0xFEE0))
      .replace(/-/g, '');
  });
  form.elements.account_number.addEventListener('input', () => {
    form.elements.account_number.value = form.elements.account_number.value
      .replace(/[０-９]/g, (character) => String.fromCharCode(character.charCodeAt(0) - 0xFEE0))
      .replace(/\D/g, '').slice(0, 7);
  });
  const birthDate = form.elements.birth_date;
  const latestAdultBirthDate = new Date(today.getFullYear(), today.getMonth(), today.getDate());
  latestAdultBirthDate.setFullYear(latestAdultBirthDate.getFullYear() - 18);
  birthDate.max = `${latestAdultBirthDate.getFullYear()}-${String(latestAdultBirthDate.getMonth() + 1).padStart(2, '0')}-${String(latestAdultBirthDate.getDate()).padStart(2, '0')}`;
  const validateBirthDate = () => {
    birthDate.setCustomValidity(birthDate.value && birthDate.value > birthDate.max
      ? '18歳未満の方はお申込みできません。'
      : '');
  };
  birthDate.addEventListener('input', validateBirthDate);
  birthDate.addEventListener('change', validateBirthDate);
  const getSameOriginEndpoint = (path) => {
    const endpoint = new URL(path, window.location.origin);
    if (endpoint.origin !== window.location.origin) throw new Error('申込APIの接続先を確認してください。');
    return endpoint.toString();
  };
  const readJsonResponse = async (response) => {
    let data = {};
    try { data = await response.json(); } catch (_) { /* API応答がJSONでない場合は下でエラー表示 */ }
    if (!response.ok) throw new Error(data.message || '送信を完了できませんでした。入力内容を確認して、もう一度お試しください。');
    return data;
  };
  const waitForEmailConfirmation = async () => {
    while (applicationWaiting && !applicationWaiting.hidden) {
      try {
        const response = await fetch(getSameOriginEndpoint(bookingConfig.applicationStatusEndpoint), {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json' }
        });
        const status = await readJsonResponse(response);
        if (status.status === 'complete' && typeof status.public_token === 'string' && status.public_token) {
          const destination = new URL(bookingConfig.applicationConfirmationPageUrl || '/visit/kyoto-city/application-pending/', window.location.origin);
          if (destination.origin !== window.location.origin) throw new Error('確認ページの接続先を確認してください。');
          destination.searchParams.set('public_token', status.public_token);
          window.location.assign(destination.toString());
          return;
        }
        if (status.status === 'expired' || status.status === 'cancelled') {
          applicationWaitingStatus.textContent = '申込状態を確認できません。お手数ですが、画面を再読み込みしてお申し込み状況をご確認ください。';
          return;
        }
        applicationWaitingStatus.textContent = '確認メール内のリンクをクリックすると、申込内容確認ページへ自動で移動します。';
      } catch (_) {
        applicationWaitingStatus.textContent = 'メール確認の状態を取得できません。通信を確認しながら自動で再確認します。';
      }
      await new Promise((resolve) => window.setTimeout(resolve, 5000));
    }
  };
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    formResult.hidden = true;
    const email = form.elements.email.value.trim().toLowerCase();
    const emailConfirm = form.elements.email_confirm.value.trim().toLowerCase();
    if (form.elements.website.value) return;
    for (const step of [4, 5, 6]) {
      if (!validateFormStep(step)) { setStep(step); return; }
    }
    if (!pickupLatitude.value || !pickupLongitude.value) {
      setStep(6);
      pickupMapStatus.textContent = '地図をクリックして集荷場所にピンを置いてください。';
      pickupMapStatus.classList.add('is-error');
      pickupMapElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    if (email !== emailConfirm) {
      setStep(4);
      form.elements.email_confirm.setCustomValidity('メールアドレスが一致しません。');
      form.elements.email_confirm.reportValidity();
      form.elements.email_confirm.setCustomValidity('');
      return;
    }
    if (!validateAccountHolder()) return;
    if (!applicationFlowReady) {
      formResult.textContent = 'プレビュー上で入力内容を確認しました。実際の仮申込保存・確認メール送信は未接続です。';
      formResult.hidden = false;
      liveMessage.textContent = 'プレビュー確認のみです。申込は保存されていません。';
      return;
    }

    const submitButton = form.querySelector('[data-application-submit]');
    if (submitButton) submitButton.disabled = true;
    try {
      const response = await fetch(getSameOriginEndpoint(bookingConfig.applicationSubmitEndpoint), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        body: new FormData(form)
      });
      const result = await readJsonResponse(response);
      if (result.status !== 'pending') throw new Error('仮申し込みの受付状態を確認できませんでした。');
      widget.querySelectorAll('[data-step]').forEach((stepPanel) => { stepPanel.hidden = true; });
      formResult.hidden = true;
      applicationWaiting.hidden = false;
      widget.classList.add('is-awaiting-email');
      liveMessage.textContent = '確認メールのリンクをお待ちください。';
      applicationWaiting.scrollIntoView({ behavior: 'smooth', block: 'center' });
      await waitForEmailConfirmation();
    } catch (error) {
      formResult.textContent = error.message || '仮申し込みを送信できませんでした。時間をおいて再度お試しください。';
      formResult.hidden = false;
      liveMessage.textContent = '送信に失敗しました。';
    } finally {
      if (submitButton) submitButton.disabled = false;
    }
  });

  renderCalendar();
  renderSlots();
  setStep(1);
})();
