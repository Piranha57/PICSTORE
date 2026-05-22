function validateLogin() {
  const username = document.getElementById('loginUsername');
  const password = document.getElementById('loginPassword');
  if (!username.value.trim() || !password.value.trim()) {
    alert('Please enter your username and password.');
    return false;
  }
  sessionStorage.setItem('picstoreLoggedIn', 'true');
  sessionStorage.setItem('picstoreUsername', username.value.trim());
  if (!sessionStorage.getItem('picstoreEmail')) {
    sessionStorage.setItem('picstoreEmail', `${username.value.trim().toLowerCase()}@example.com`);
  }
  if (!sessionStorage.getItem('picstoreMemberSince')) {
    sessionStorage.setItem('picstoreMemberSince', new Date().toLocaleDateString('en-US', { month: 'long', year: 'numeric' }));
  }
  sessionStorage.setItem('picstoreRole', 'Content Creator');
  alert('Login submitted successfully.');
  window.location.href = 'profile.html';
  return false;
}

function validateRegister() {
  const username = document.getElementById('registerUsername');
  const email = document.getElementById('registerEmail');
  const password = document.getElementById('registerPassword');
  const confirm = document.getElementById('registerConfirm');
  if (!username.value.trim() || !email.value.trim() || !password.value.trim() || !confirm.value.trim()) {
    alert('Please complete every field before registering.');
    return false;
  }
  if (password.value !== confirm.value) {
    alert('Passwords do not match.');
    return false;
  }
  sessionStorage.setItem('picstoreLoggedIn', 'true');
  sessionStorage.setItem('picstoreUsername', username.value.trim());
  sessionStorage.setItem('picstoreEmail', email.value.trim());
  sessionStorage.setItem('picstoreMemberSince', new Date().toLocaleDateString('en-US', { month: 'long', year: 'numeric' }));
  sessionStorage.setItem('picstoreRole', 'Content Creator');
  alert('Registration completed successfully.');
  window.location.href = 'profile.html';
  return false;
}

function validateBlog() {
  if (typeof isUserLoggedIn === 'function' && !isUserLoggedIn()) {
    alert('You must be logged in before publishing a blog. Redirecting to login.');
    window.location.href = 'login.html';
    return false;
  }
  const title = document.getElementById('blogTitle');
  const content = document.getElementById('blogContent');
  if (!title.value.trim() || !content.value.trim()) {
    alert('Please enter both a title and content for your blog.');
    return false;
  }

  const snippet = content.value.trim().slice(0, 120) + (content.value.trim().length > 120 ? '...' : '');
  if (typeof saveBlogHistory === 'function') {
    saveBlogHistory({
      title: title.value.trim(),
      snippet,
      createdAt: new Date().toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' })
    });
  }

  alert('Blog published successfully.');
  return false;
}
