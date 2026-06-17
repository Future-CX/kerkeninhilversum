document.addEventListener("DOMContentLoaded", () => {
  const searchInput = document.querySelector("#search");
  const chips = Array.from(document.querySelectorAll(".chip"));
  const cards = Array.from(document.querySelectorAll(".church-card"));
  const emptyState = document.querySelector("#emptyState");

  if (!searchInput || chips.length === 0 || cards.length === 0 || !emptyState) {
    return;
  }

  let activeFilter = "all";

  function normalize(value) {
    return value.trim().toLowerCase();
  }

  function updateDirectory() {
    const query = normalize(searchInput.value);
    let visibleCount = 0;

    cards.forEach((card) => {
      const categoryMatches = activeFilter === "all" || card.dataset.category === activeFilter;
      const searchText = `${card.textContent} ${card.dataset.search || ""}`.toLowerCase();
      const queryMatches = !query || searchText.includes(query);
      const isVisible = categoryMatches && queryMatches;

      card.classList.toggle("is-hidden", !isVisible);
      card.setAttribute("aria-hidden", String(!isVisible));
      if (isVisible) visibleCount += 1;
    });

    emptyState.hidden = visibleCount !== 0;
  }

  chips.forEach((chip) => {
    chip.addEventListener("click", () => {
      activeFilter = chip.dataset.filter || "all";
      chips.forEach((item) => {
        const isActive = item === chip;
        item.classList.toggle("active", isActive);
        item.setAttribute("aria-pressed", String(isActive));
      });
      updateDirectory();
    });
  });

  chips.forEach((chip) => {
    chip.setAttribute("aria-pressed", String(chip.classList.contains("active")));
  });

  searchInput.addEventListener("input", updateDirectory);
  updateDirectory();
});
