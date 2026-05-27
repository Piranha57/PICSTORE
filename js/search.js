function escapeHtml(text) {
  return text.replace(/[&<>"'`]/g, char => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
    '`': '&#96;'
  }[char] || char));
}

function searchContent(searchFieldId) {
  const input = document.getElementById(searchFieldId);
  if (!input) return;

  const query = input.value.trim();
  if (!query) {
    alert('Enter a search term to look up blog posts, images, or users.');
    return;
  }

  const resultsContainer = document.getElementById('searchResults');
  if (resultsContainer) {
    const escaped = escapeHtml(query);
    resultsContainer.innerHTML = `
      <div class="search-note"><strong>Showing results for:</strong> ${escaped}</div>
      <article class="search-result-card">
        <h3>Blog results for ${escaped}</h3>
        <p>Use keywords like title or author to find matching blog posts faster.</p>
      </article>
      <article class="search-result-card">
        <h3>Gallery results for ${escaped}</h3>
        <p>Search image titles, tags, and descriptions to locate your best photos.</p>
      </article>
      <article class="search-result-card">
        <h3>User profiles for ${escaped}</h3>
        <p>Discover creators, contributors, and collaborators related to your query.</p>
      </article>
    `;
    return;
  }

  alert(`Search results are coming soon for: ${query}`);
}
