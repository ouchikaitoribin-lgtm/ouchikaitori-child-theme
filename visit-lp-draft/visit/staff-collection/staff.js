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

  document.querySelectorAll('.js-image-compress-form').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      if (form.dataset.compressed === '1') return;
      event.preventDefault();
      const photoInput = form.querySelector('[data-max-files]');
      if (photoInput && photoInput.files.length > Number(photoInput.dataset.maxFiles || 5)) {
        window.alert('一度に選択できる写真は5枚までです。');
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
