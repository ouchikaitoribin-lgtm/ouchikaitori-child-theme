(function () {
  'use strict';

  const url = new URL(window.location.href);
  const publicToken = url.searchParams.get('public_token');
  const application = window.VISIT_PENDING_APPLICATION;
  const pendingNote = document.querySelector('[data-pending-data-note]');
  const contactlessDetails = document.querySelector('[data-pending-contactless]');
  const tokenStatus = document.querySelector('[data-public-token-status]');
  const invalidNotice = document.querySelector('[data-public-token-invalid]');
  const hasToken = typeof publicToken === 'string' && publicToken.length > 0;

  // public_tokenの存在だけでは本人確認済み・申込完了とみなさず、サーバー検証結果を必須にします。
  const isValidCompletedToken = hasToken
    && application
    && application.public_token_valid === true
    && application.application_status === 'complete';

  const showState = (state, visible) => {
    document.querySelectorAll('[data-application-state="' + state + '"]').forEach((element) => {
      element.hidden = !visible;
    });
  };

  showState('pending', !hasToken);
  showState('complete', isValidCompletedToken);

  if (hasToken && !isValidCompletedToken) {
    if (invalidNotice) invalidNotice.hidden = false;
    document.querySelectorAll('.visit-pending-main > section:not(.visit-pending-hero)').forEach((element) => {
      element.hidden = true;
    });
    return;
  }

  if (isValidCompletedToken && tokenStatus) {
    tokenStatus.textContent = 'メール確認済みの正式なお申し込み内容です。';
    tokenStatus.hidden = false;
  }

  if (!application || typeof application !== 'object') return;

  const formatValue = (value) => {
    if (Array.isArray(value)) return value.join('・');
    if (value === null || value === undefined || value === '') return '';
    return String(value);
  };
  document.querySelectorAll('[data-pending-field]').forEach((element) => {
    const key = element.dataset.pendingField;
    const value = formatValue(application[key]);
    if (!value) return;
    element.textContent = value;
    if (key === 'pickup_code') element.classList.add('is-issued');
  });

  const hasApplicationData = Object.entries(application).some(([key, value]) => (
    !['public_token', 'public_token_valid', 'application_status'].includes(key)
    && value !== null && value !== undefined && value !== ''
  ));
  if (hasApplicationData && pendingNote) pendingNote.hidden = true;

  const handoff = application.handoff_method;
  if (contactlessDetails) contactlessDetails.hidden = handoff !== 'contactless';
  if (handoff === 'face' || handoff === 'contactless') {
    document.querySelectorAll('[data-handoff-guide]').forEach((guide) => {
      guide.hidden = guide.dataset.handoffGuide !== handoff;
    });
    const uploadNote = document.querySelector('[data-identity-upload-note]');
    if (uploadNote && handoff === 'face') uploadNote.hidden = true;
  }
})();
