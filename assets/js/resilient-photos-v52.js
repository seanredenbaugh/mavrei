(() => {
  const images = Array.from(document.querySelectorAll('img[data-resilient-src]'));

  const retryUrl = (source, attempt) => {
    const url = new URL(source, document.baseURI);
    url.searchParams.set('photo_retry', `${Date.now()}-${attempt}`);
    return url.href;
  };

  const loadImage = image => new Promise(resolve => {
    const source = image.dataset.resilientSrc;
    let attempt = 0;
    let finished = false;

    const finish = success => {
      if (finished) return;
      finished = true;
      image.onload = null;
      image.onerror = null;
      image.dataset.photoLoaded = success ? 'yes' : 'no';
      image.classList.toggle('photo-load-failed', !success);
      resolve(success);
    };

    const start = () => {
      attempt += 1;
      image.onload = () => finish(true);
      image.onerror = () => {
        if (attempt >= 4) {
          finish(false);
          return;
        }
        window.setTimeout(start, attempt * 900);
      };
      image.src = attempt === 1 ? source : retryUrl(source, attempt);
    };

    start();
  });

  let nextIndex = 0;
  const worker = async () => {
    while (nextIndex < images.length) {
      const image = images[nextIndex++];
      await loadImage(image);
    }
  };

  const workerCount = Math.min(2, images.length);
  window.mavreiPhotosReady = Promise.all(Array.from({length: workerCount}, worker));
  window.mavreiPhotoRetryUrl = retryUrl;
})();
