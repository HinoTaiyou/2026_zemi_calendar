(function () {
  const chipBtn = document.getElementById("user-chip-btn");
  const dropdown = document.getElementById("user-dropdown");
  const loginLink = document.getElementById("login-link");
  const avatar = document.getElementById("user-avatar");
  const avatarMenu = document.getElementById("user-avatar-menu");
  const nickname = document.getElementById("user-nickname");
  const nicknameMenu = document.getElementById("user-nickname-menu");
  const logoutBtn = document.getElementById("user-logout-btn");

  if (!chipBtn || !dropdown || !loginLink || typeof getCurrentUser !== "function") {
    return;
  }

  function closeDropdown() {
    dropdown.hidden = true;
    chipBtn.setAttribute("aria-expanded", "false");
    chipBtn.classList.remove("is-open");
  }

  function openDropdown() {
    dropdown.hidden = false;
    chipBtn.setAttribute("aria-expanded", "true");
    chipBtn.classList.add("is-open");
  }

  function toggleDropdown() {
    if (dropdown.hidden) {
      openDropdown();
    } else {
      closeDropdown();
    }
  }

  function setUserInitial(el, name) {
    if (!el) {
      return;
    }
    const initial = String(name).trim().slice(0, 1).toUpperCase() || "?";
    el.textContent = initial;
  }

  const user = getCurrentUser();
  if (user && user.nickname) {
    setUserInitial(avatar, user.nickname);
    setUserInitial(avatarMenu, user.nickname);
    nickname.textContent = user.nickname;
    if (nicknameMenu) {
      nicknameMenu.textContent = user.nickname;
    }
    chipBtn.hidden = false;
    loginLink.hidden = true;
  }

  chipBtn.addEventListener("click", (event) => {
    event.stopPropagation();
    toggleDropdown();
  });

  document.addEventListener("click", () => {
    closeDropdown();
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      closeDropdown();
    }
  });

  dropdown.addEventListener("click", (event) => {
    event.stopPropagation();
  });

  if (logoutBtn) {
    logoutBtn.addEventListener("click", () => {
      if (typeof logout === "function") {
        logout();
      }
    });
  }
})();
