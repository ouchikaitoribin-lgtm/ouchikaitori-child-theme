(function () {
  'use strict';

  document.querySelectorAll('[data-copy-value]').forEach((button) => {
    button.addEventListener('click', async () => {
      const value = button.dataset.copyValue || '';
      const status = document.querySelector('[data-copy-status]');
      try {
        await navigator.clipboard.writeText(value);
      } catch (_) {
        const field = document.createElement('textarea');
        field.value = value;
        field.setAttribute('readonly', '');
        field.style.position = 'fixed';
        field.style.opacity = '0';
        document.body.appendChild(field);
        field.select();
        document.execCommand('copy');
        field.remove();
      }
      if (status) status.textContent = 'コピーしました。';
    });
  });

  const compressFile = async (file) => {
    if (!window.createImageBitmap || !HTMLCanvasElement.prototype.toBlob || typeof DataTransfer === 'undefined') return file;
    const bitmap = await createImageBitmap(file);
    const scale = Math.min(1, 1600 / bitmap.width);
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(bitmap.width * scale));
    canvas.height = Math.max(1, Math.round(bitmap.height * scale));
    const context = canvas.getContext('2d', { alpha: false });
    context.fillStyle = '#fff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close();
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.82));
    if (!blob) return file;
    return new File([blob], 'visit-photo.jpg', { type: 'image/jpeg', lastModified: Date.now() });
  };

  const activePreviewUrls = new Map();
  const activePreviewElements = new WeakMap();

  const renderFilePreviews = (input) => {
    const oldUrls = activePreviewUrls.get(input) || [];
    oldUrls.forEach((url) => URL.revokeObjectURL(url));
    const urls = [];
    const label = input.closest('.visit-staff-field');
    if (!label) return;

    let preview = activePreviewElements.get(input);
    if (!preview) {
      preview = document.createElement('div');
      preview.className = 'visit-staff-file-preview';
      label.insertAdjacentElement('afterend', preview);
      activePreviewElements.set(input, preview);
    }
    preview.replaceChildren();

    const files = Array.from(input.files || []);
    const maxFiles = Number(input.dataset.maxFiles || 0);
    const summary = document.createElement('p');
    summary.className = 'visit-staff-file-preview__summary';
    summary.setAttribute('aria-live', 'polite');
    summary.textContent = files.length
      ? '選択中：' + files.length + '枚' + (maxFiles && files.length > maxFiles ? '（最大' + maxFiles + '枚）' : '')
      : '写真を選択すると、送信前にプレビューを確認できます。';
    if (maxFiles && files.length > maxFiles) summary.classList.add('is-error');
    preview.appendChild(summary);

    if (files.length) {
      const grid = document.createElement('div');
      grid.className = 'visit-staff-file-preview__grid';
      grid.setAttribute('role', 'group');
      grid.setAttribute('aria-label', '選択した画像のプレビュー');
      files.forEach((file, index) => {
        if (!file.type || !file.type.startsWith('image/')) return;
        const url = URL.createObjectURL(file);
        urls.push(url);
        const figure = document.createElement('figure');
        figure.className = 'visit-staff-file-preview__item';
        const image = document.createElement('img');
        image.src = url;
        image.alt = '選択した画像' + (index + 1) + 'のプレビュー';
        image.loading = 'lazy';
        const caption = document.createElement('figcaption');
        caption.textContent = input.dataset.previewLabel || ('写真 ' + (index + 1));
        figure.append(image, caption);
        grid.appendChild(figure);
      });
      preview.appendChild(grid);
    }
    activePreviewUrls.set(input, urls);
  };

  const fileInputs = document.querySelectorAll('.js-image-compress-form input[type="file"]');
  fileInputs.forEach((input) => {
    input.addEventListener('change', () => renderFilePreviews(input));
    renderFilePreviews(input);
  });
  window.addEventListener('pagehide', () => {
    activePreviewUrls.forEach((urls) => urls.forEach((url) => URL.revokeObjectURL(url)));
    activePreviewUrls.clear();
  });

  document.querySelectorAll('[data-collection-photos]').forEach((group) => {
    const updateCount = () => {
      const count = Array.from(group.querySelectorAll('input[type="file"]'))
        .filter((input) => input.files && input.files.length > 0).length;
      const output = group.querySelector('[data-collection-photo-count]');
      if (output) output.textContent = '撮影済み：' + count + ' / 5枚';
    };
    group.querySelectorAll('input[type="file"]').forEach((input) => {
      input.addEventListener('change', updateCount);
    });
    updateCount();
  });

  const uploadForms = Array.from(document.querySelectorAll('.js-image-compress-form'));

  const getOtherFormWithPendingFiles = (currentForm) => uploadForms.find((otherForm) => {
    if (otherForm === currentForm) return false;
    return Array.from(otherForm.querySelectorAll('input[type="file"]'))
      .some((input) => input.files && input.files.length > 0);
  });

  const blockWhenOtherFormHasPendingFiles = (currentForm) => {
    const pendingForm = getOtherFormWithPendingFiles(currentForm);
    if (!pendingForm) return false;

    const pendingButton = pendingForm.querySelector('button[type="submit"], input[type="submit"]');
    const buttonLabel = pendingButton && (pendingButton.textContent || pendingButton.value || '').trim();
    const instruction = buttonLabel
      ? '先に「' + buttonLabel + '」を押して画像を保存してください。保存後、こちらを送信してください。'
      : '先にもう一方のフォームの保存ボタンを押して画像を保存してください。保存後、こちらを送信してください。';
    window.alert('もう一方のフォームに未送信の画像があります。この送信では、もう一方の画像は保存されません。\n' + instruction);
    if (pendingButton && !pendingButton.disabled) pendingButton.focus();
    return true;
  };

  uploadForms.forEach((form) => {
    form.addEventListener('click', (event) => {
      const target = event.target;
      const submitButton = target && typeof target.closest === 'function'
        ? target.closest('button[type="submit"], input[type="submit"]')
        : null;
      if (!submitButton || !form.contains(submitButton)) return;
      if (blockWhenOtherFormHasPendingFiles(form)) event.preventDefault();
    });
    form.addEventListener('submit', async (event) => {
      if (form.dataset.compressed === '1') return;
      event.preventDefault();
      if (blockWhenOtherFormHasPendingFiles(form)) return;
      const photoGroup = form.querySelector('[data-collection-photos]');
      const photoInputs = photoGroup ? Array.from(photoGroup.querySelectorAll('input[type="file"]')) : [];
      const selectedPhotoInputs = photoInputs.filter((input) => input.files && input.files.length > 0);
      const maxPhotos = Number(photoGroup && photoGroup.dataset.maxFiles || 5);
      if (photoGroup && selectedPhotoInputs.length < 1) {
        window.alert('回収前写真を1枚以上撮影してください。');
        if (photoInputs[0]) photoInputs[0].focus();
        return;
      }
      if (selectedPhotoInputs.length > maxPhotos) {
        window.alert('一度に送信できる回収前写真は5枚までです。');
        if (photoInputs[0]) photoInputs[0].focus();
        return;
      }
      const submit = form.querySelector('button[type="submit"]');
      if (submit) {
        submit.disabled = true;
        submit.textContent = '画像を圧縮して送信しています…';
      }
      try {
        for (const input of form.querySelectorAll('input[type="file"]')) {
          if (!input.files || !input.files.length) continue;
          try {
            const transfer = new DataTransfer();
            for (const file of input.files) transfer.items.add(await compressFile(file));
            input.files = transfer.files;
            renderFilePreviews(input);
          } catch (_) {
            // Keep original files; the server still validates and recompresses them.
          }
        }
        form.dataset.compressed = '1';
        HTMLFormElement.prototype.submit.call(form);
      } catch (_) {
        if (submit) {
          submit.disabled = false;
          submit.textContent = '再試行';
        }
        window.alert('画像を準備できませんでした。ファイルを選び直して再度お試しください。');
      }
    });
  });
})();
