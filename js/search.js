function searchContent(searchFieldId) {
  const input = document.getElementById(searchFieldId);
  if (!input) return;
  const query = input.value.trim();
  if (!query) {
    alert('Enter a search term to look up blog posts, images, or users.');
    return;
  }
  alert(`Search results are coming soon for: ${query}`);
}
