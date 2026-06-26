document.addEventListener("DOMContentLoaded", () => {
  const searchInput = document.querySelector("#search");
  const chips = Array.from(document.querySelectorAll(".chip"));
  const cards = Array.from(document.querySelectorAll(".church-card"));
  const emptyState = document.querySelector("#emptyState");
  const signupForm = document.querySelector("#zomerfeestSignupForm");

  function normalize(value) {
    return value.trim().toLowerCase();
  }

  if (searchInput && chips.length > 0 && cards.length > 0 && emptyState) {
    let activeFilter = "all";

    function updateDirectory() {
      const query = normalize(searchInput.value);
      let visibleCount = 0;

      cards.forEach((card) => {
        const categoryMatches =
          activeFilter === "all" || card.dataset.category === activeFilter;
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
  }

  if (signupForm) {
    const status = signupForm.querySelector("[data-form-status]");
    const submitButton = signupForm.querySelector("button[type='submit']");
    const formStartedAt = signupForm.querySelector("[data-form-started-at]");
    const storageKey = "zomerfeestSignupForm";
    let lastSubmittedPayload = "";

    function setStatus(message, type) {
      if (!status) return;
      status.textContent = message;
      status.dataset.status = type;
    }

    function getSignupPayload() {
      const formData = new FormData(signupForm);
      return {
        name: formData.get("name") || "",
        email: formData.get("email") || "",
        age: formData.get("age") || "",
        churchOrCity: formData.get("churchOrCity") || "",
        bringsFriend: formData.get("bringsFriend") === "true",
        friendName: formData.get("friendName") || "",
      };
    }

    function serializeSignupPayload(payload) {
      return JSON.stringify(payload);
    }

    function getSubmissionPayload() {
      const formData = new FormData(signupForm);
      return {
        ...getSignupPayload(),
        website: formData.get("website") || "",
        formStartedAt: formData.get("formStartedAt") || "",
      };
    }

    function updateSubmitButtonState() {
      if (!submitButton || !lastSubmittedPayload) return;
      submitButton.disabled =
        serializeSignupPayload(getSignupPayload()) === lastSubmittedPayload;
    }

    function saveSignupPayload() {
      try {
        sessionStorage.setItem(storageKey, JSON.stringify(getSignupPayload()));
      } catch (error) {
        // Ignore storage failures; the form can still be submitted normally.
      }
    }

    function restoreSignupPayload() {
      try {
        const savedPayload = JSON.parse(sessionStorage.getItem(storageKey) || "null");
        if (!savedPayload) return;

        Object.entries(savedPayload).forEach(([name, value]) => {
          if (name === "bringsFriend") {
            const radio = signupForm.querySelector(
              `[name="bringsFriend"][value="${value ? "true" : "false"}"]`,
            );
            if (radio) radio.checked = true;
            return;
          }

          const field = signupForm.elements.namedItem(name);
          if (field) field.value = value;
        });
      } catch (error) {
        sessionStorage.removeItem(storageKey);
      }
    }

    restoreSignupPayload();
    if (formStartedAt) {
      formStartedAt.value = String(Date.now());
    }
    signupForm.addEventListener("input", () => {
      saveSignupPayload();
      updateSubmitButtonState();
    });
    signupForm.addEventListener("change", () => {
      saveSignupPayload();
      updateSubmitButtonState();
    });

    signupForm.addEventListener("submit", async (event) => {
      event.preventDefault();

      if (!signupForm.reportValidity()) {
        return;
      }

      const payload = getSignupPayload();
      const payloadSignature = serializeSignupPayload(payload);
      saveSignupPayload();

      setStatus("Je aanmelding wordt verstuurd...", "pending");
      if (submitButton) submitButton.disabled = true;

      try {
        const response = await fetch(signupForm.action, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
          },
          body: JSON.stringify(getSubmissionPayload()),
        });

        if (!response.ok) {
          const result = await response.json().catch(() => ({}));
          throw new Error(result.error || "Aanmelden is niet gelukt.");
        }

        lastSubmittedPayload = payloadSignature;
        setStatus("Dank je wel, je aanmelding is ontvangen.", "success");
      } catch (error) {
        setStatus(
          "Aanmelden lukt nu niet. Mail je gegevens naar website@kerkeninhilversum.nl.",
          "error",
        );
      } finally {
        if (submitButton) {
          submitButton.disabled =
            Boolean(lastSubmittedPayload) &&
            serializeSignupPayload(getSignupPayload()) === lastSubmittedPayload;
        }
      }
    });
  }
});
