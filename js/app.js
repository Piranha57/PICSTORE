document.addEventListener('DOMContentLoaded', () => {
  const links = document.querySelectorAll('.menu-item');
  links.forEach(link => {
    if (link.href === window.location.href || link.href === document.location.origin + document.location.pathname) {
      link.classList.add('active');
    }
  });
  updateAuthUI();
  loadProfileData();
});

function loadProfileData() {
  if (!window.location.pathname.endsWith('profile.html')) return;
  if (!isUserLoggedIn()) {
    window.location.href = 'login.html';
    return;
  }

  const username = sessionStorage.getItem('picstoreUsername') || 'User';
  const email = sessionStorage.getItem('picstoreEmail') || `${username.toLowerCase().replace(/\s+/g, '')}@example.com`;
  const memberSince = sessionStorage.getItem('picstoreMemberSince') || new Date().toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
  const role = sessionStorage.getItem('picstoreRole') || 'Content Creator';
  const avatarText = username.split(' ').map(part => part[0]).join('').slice(0, 2).toUpperCase();

  const profileUsername = document.getElementById('profileUsername');
  const profileEmail = document.getElementById('profileEmail');
  const profileMemberSince = document.getElementById('profileMemberSince');
  const profileRole = document.getElementById('profileRole');
  const profileAvatar = document.getElementById('profileAvatar');

  if (profileUsername) profileUsername.textContent = username;
  if (profileEmail) profileEmail.textContent = email;
  if (profileMemberSince) profileMemberSince.textContent = memberSince;
  if (profileRole) profileRole.textContent = role;
  if (profileAvatar) profileAvatar.textContent = avatarText;
  renderProfileHistory();
}

function getStoredHistory(key) {
  const raw = sessionStorage.getItem(key);
  if (!raw) return [];
  try {
    const history = JSON.parse(raw);
    return Array.isArray(history) ? history : [];
  } catch {
    return [];
  }
}

function saveHistoryItem(key, item) {
  const history = getStoredHistory(key);
  history.unshift(item);
  sessionStorage.setItem(key, JSON.stringify(history.slice(0, 20)));
}

function saveBlogHistory(blog) {
  saveHistoryItem('picstoreBlogHistory', blog);
}

function saveImageHistory(image) {
  saveHistoryItem('picstoreImageHistory', image);
}

function renderProfileHistory() {
  const blogHistory = getStoredHistory('picstoreBlogHistory');
  const imageHistory = getStoredHistory('picstoreImageHistory');

  const blogHistoryContainer = document.getElementById('blogHistory');
  const imageHistoryContainer = document.getElementById('imageHistory');

  if (blogHistoryContainer) {
    blogHistoryContainer.innerHTML = blogHistory.length
      ? blogHistory.map(blog => `<div class="history-item"><strong>${blog.title}</strong><span>${blog.createdAt}</span><p>${blog.snippet}</p></div>`).join('')
      : '<div class="empty-state"><p>No blog posts yet.</p></div>';
  }

  if (imageHistoryContainer) {
    imageHistoryContainer.innerHTML = imageHistory.length
      ? imageHistory.map(image => `<div class="history-item"><strong>${image.title}</strong><span>${image.uploadedAt}</span>${image.tags ? `<p>Tags: ${image.tags}</p>` : ''}</div>`).join('')
      : '<div class="empty-state"><p>No images uploaded yet.</p></div>';
  }
}

function searchContent(searchFieldId) {
  const input = document.getElementById(searchFieldId);
  if (!input) return;
  const query = input.value.trim();
  if (!query) {
    alert('Please enter a search term to find content.');
    return;
  }
  alert(`Searching for: ${query}`);
}

function isUserLoggedIn() {
  return sessionStorage.getItem('picstoreLoggedIn') === 'true';
}

function logoutUser() {
  sessionStorage.removeItem('picstoreLoggedIn');
  sessionStorage.removeItem('picstoreUsername');
  sessionStorage.removeItem('picstoreEmail');
  sessionStorage.removeItem('picstoreMemberSince');
  sessionStorage.removeItem('picstoreRole');
  window.location.href = 'login.html';
}

function requireLogin(actionName) {
  if (!isUserLoggedIn()) {
    alert(`${actionName} requires login. Redirecting to the login page.`);
    window.location.href = 'login.html';
    return false;
  }
  return true;
}

function updateAuthUI() {
  const loggedIn = isUserLoggedIn();
  const loginLinks = document.querySelectorAll('.menu-item[href="login.html"], .text-link[href="login.html"]');
  const registerLinks = document.querySelectorAll('.menu-item[href="register.html"], .text-link[href="register.html"]');
  loginLinks.forEach(link => {
    if (loggedIn) {
      link.textContent = 'Logout';
      link.href = 'javascript:void(0)';
      link.onclick = logoutUser;
      link.classList.add('logout-link');
      if (link.parentElement) link.parentElement.appendChild(link);
    } else {
      link.textContent = 'Login';
      link.href = 'login.html';
      link.onclick = null;
      link.classList.remove('logout-link');
    }
  });
  registerLinks.forEach(link => {
    link.style.display = loggedIn ? 'none' : '';
  });

  const profileLinks = document.querySelectorAll('.menu-item[href="profile.html"]');
  profileLinks.forEach(link => {
    link.style.display = loggedIn ? '' : 'none';
  });

  const authNotice = document.getElementById('authNotice');
  if (authNotice) {
    if (!loggedIn) {
      authNotice.innerHTML = '<strong style="color: #d9534f; font-size: 1.1em;">⚠️ Cannot upload, must register or login first</strong><br><a href="login.html">Login now</a> or <a href="register.html">Register here</a>.';
    } else {
      authNotice.innerHTML = '';
    }
  }

  const blogForm = document.getElementById('blogForm');
  const galleryForm = document.getElementById('galleryAddForm');
  if (blogForm) {
    const submitButton = blogForm.querySelector('button[type="submit"]');
    if (submitButton) submitButton.disabled = !loggedIn;
  }
  if (galleryForm) {
    const submitButton = galleryForm.querySelector('button[type="submit"]');
    if (submitButton) submitButton.disabled = !loggedIn;
  }
}
