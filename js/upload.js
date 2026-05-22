function previewImage(event) {
  const preview = document.getElementById('imagePreview');
  const file = event.target.files && event.target.files[0];
  if (!file) {
    preview.innerHTML = 'No image selected';
    return;
  }
  const reader = new FileReader();
  reader.onload = () => {
    preview.innerHTML = `<img src="${reader.result}" alt="Selected image preview">`;
  };
  reader.readAsDataURL(file);
}

function previewGalleryImage(event) {
  const preview = document.getElementById('galleryAddPreview');
  const file = event.target.files && event.target.files[0];
  if (!preview) return;

  if (!file) {
    preview.innerHTML = 'No image selected';
    return;
  }

  const reader = new FileReader();
  reader.onload = () => {
    preview.innerHTML = `<img src="${reader.result}" alt="Selected gallery preview">`;
  };
  reader.readAsDataURL(file);
}

function handleGalleryUpload(event) {
  const file = event.target.files && event.target.files[0];
  const galleryGrid = document.getElementById('galleryGrid');
  if (!file || !galleryGrid) return;

  const reader = new FileReader();
  reader.onload = () => {
    const card = document.createElement('div');
    card.className = 'gallery-item';
    card.innerHTML = `
      <img src="${reader.result}" alt="Uploaded gallery image">
      <div class="gallery-caption">${file.name}</div>
    `;
    const placeholder = galleryGrid.querySelector('.empty-state');
    if (placeholder) placeholder.remove();
    galleryGrid.prepend(card);
  };
  reader.readAsDataURL(file);
}

function addGalleryImage(event) {
  event.preventDefault();
  if (typeof requireLogin === 'function' && !requireLogin('Adding an image')) {
    return false;
  }

  const fileInput = document.getElementById('galleryUpload');
  const titleInput = document.getElementById('galleryImageTitle');
  const tagsInput = document.getElementById('galleryImageTags');
  const galleryGrid = document.getElementById('galleryGrid');

  if (!fileInput || !galleryGrid || !titleInput || !tagsInput) return false;
  const file = fileInput.files && fileInput.files[0];
  if (!file) {
    alert('Please choose an image before adding it to the gallery.');
    return false;
  }

  const reader = new FileReader();
  reader.onload = () => {
    const tags = tagsInput.value.trim();
    const card = document.createElement('div');
    card.className = 'gallery-item';
    card.innerHTML = `
      <img src="${reader.result}" alt="${titleInput.value}">
      <div class="gallery-caption">
        <strong>${titleInput.value}</strong>
        ${tags ? `<p class="gallery-tags">${tags}</p>` : ''}
      </div>
    `;
    const placeholder = galleryGrid.querySelector('.empty-state');
    if (placeholder) placeholder.remove();
    galleryGrid.prepend(card);

    if (typeof saveImageHistory === 'function') {
      saveImageHistory({
        title: titleInput.value.trim() || file.name,
        tags,
        uploadedAt: new Date().toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' })
      });
    }

    fileInput.value = '';
    titleInput.value = '';
    tagsInput.value = '';
    const preview = document.getElementById('galleryAddPreview');
    if (preview) preview.innerHTML = 'No image selected';
  };
  reader.readAsDataURL(file);
  return false;
}
