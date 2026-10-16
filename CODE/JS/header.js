(function () {
  "use strict";

  const THEME_KEY = "nexgen-theme";

  const sidebar = document.getElementById("sidebar");
  const openSidebar = document.getElementById("openSidebar");
  const closeSidebar = document.getElementById("closeSidebar");
  const overlay = document.getElementById("overlay");

  const categoryToggle = document.getElementById("categoryToggle");
  const categoryMenu = document.getElementById("categoryMenu");
  const dropdownArrow = document.getElementById("dropdownArrow");

  const themeToggle = document.getElementById("themeToggle");
  const themeIcon = document.getElementById("themeIcon");

  /* =========================
     THEME
     ========================= */

  function normalizeTheme(theme) {
    return theme === "light" ? "light" : "dark";
  }

  function getCurrentTheme() {
    return normalizeTheme(document.documentElement.getAttribute("data-theme"));
  }

  function syncThemeToggle(theme) {
    const currentTheme = normalizeTheme(theme);

    if (themeIcon) {
      themeIcon.className =
        currentTheme === "light" ? "bi bi-sun-fill" : "bi bi-moon-stars-fill";
    }

    if (themeToggle) {
      const isLight = currentTheme === "light";

      themeToggle.setAttribute(
        "aria-label",
        isLight ? "Switch to dark mode" : "Switch to light mode",
      );

      themeToggle.setAttribute("aria-pressed", isLight ? "true" : "false");
    }
  }

  function applyTheme(theme, persist = true) {
    const nextTheme = normalizeTheme(theme);

    document.documentElement.setAttribute("data-theme", nextTheme);

    if (persist) {
      try {
        localStorage.setItem(THEME_KEY, nextTheme);
      } catch (error) {
        console.warn("Could not save NexGen theme preference.", error);
      }
    }

    syncThemeToggle(nextTheme);

    /* Settings.js can listen for this so its selected card updates too. */
    window.dispatchEvent(
      new CustomEvent("nexgen-theme-change", {
        detail: { theme: nextTheme },
      }),
    );
  }

  /* theme_init.php should set this before first paint.
     This fallback is kept for pages that forgot to include theme_init.php. */
  try {
    const savedTheme = localStorage.getItem(THEME_KEY);
    if (
      !document.documentElement.getAttribute("data-theme") &&
      (savedTheme === "light" || savedTheme === "dark")
    ) {
      document.documentElement.setAttribute("data-theme", savedTheme);
    }
  } catch (error) {
    console.warn("Could not restore theme preference:", error);
  }

  syncThemeToggle(getCurrentTheme());

  if (themeToggle) {
    themeToggle.addEventListener("click", function () {
      const nextTheme = getCurrentTheme() === "light" ? "dark" : "light";

      applyTheme(nextTheme, true);
    });
  }

  /* Sync the current page if another browser tab changes the preference. */
  window.addEventListener("storage", function (event) {
    if (
      event.key === THEME_KEY &&
      (event.newValue === "light" || event.newValue === "dark")
    ) {
      applyTheme(event.newValue, false);
    }
  });

  /* =========================
     SIDEBAR
     ========================= */

  let previousBodyOverflow = "";
  let lastFocusedElement = null;

  function setSidebarState(isOpen) {
    if (sidebar) {
      sidebar.classList.toggle("active", isOpen);
      sidebar.setAttribute("aria-hidden", isOpen ? "false" : "true");
      sidebar.toggleAttribute("inert", !isOpen);
    }

    if (overlay) {
      overlay.classList.toggle("show", isOpen);
      overlay.setAttribute("aria-hidden", isOpen ? "false" : "true");
    }

    if (openSidebar) {
      openSidebar.setAttribute("aria-expanded", isOpen ? "true" : "false");
      openSidebar.setAttribute(
        "aria-label",
        isOpen ? "Close navigation menu" : "Open navigation menu",
      );
    }

    if (isOpen) {
      previousBodyOverflow = document.body.style.overflow;
      document.body.style.overflow = "hidden";
    } else {
      document.body.style.overflow = previousBodyOverflow;
    }
  }

  function openSidebarMenu() {
    if (!sidebar || sidebar.classList.contains("active")) {return;}

    lastFocusedElement = document.activeElement;
    setSidebarState(true);

    if (closeSidebar) {
      window.requestAnimationFrame(function () {
        closeSidebar.focus();
      });
    }
  }

  function closeSidebarMenu(restoreFocus = true) {
    if (!sidebar) {return;}

    setSidebarState(false);

    if (
      restoreFocus &&
      lastFocusedElement &&
      typeof lastFocusedElement.focus === "function"
    ) {
      lastFocusedElement.focus();
    }

    lastFocusedElement = null;
  }

  if (sidebar && !sidebar.classList.contains("active")) {
    sidebar.setAttribute("inert", "");
  }

  /* Keep these exports for pages that may still call the functions directly. */
  window.openSidebarMenu = openSidebarMenu;
  window.closeSidebarMenu = closeSidebarMenu;

  if (openSidebar) {
    openSidebar.addEventListener("click", function () {
      if (sidebar && sidebar.classList.contains("active")) {
        closeSidebarMenu();
      } else {
        openSidebarMenu();
      }
    });
  }

  if (closeSidebar) {
    closeSidebar.addEventListener("click", function () {
      closeSidebarMenu();
    });
  }

  if (overlay) {
    overlay.addEventListener("click", function () {
      closeSidebarMenu();
    });
  }

  if (sidebar) {
    sidebar.addEventListener("click", function (event) {
      const link = event.target.closest("a[href]");

      if (link && link.getAttribute("href") !== "#") {
        closeSidebarMenu(false);
      }
    });
  }

  document.addEventListener("keydown", function (event) {
    if (!sidebar || !sidebar.classList.contains("active")) {return;}

    if (event.key === "Escape") {
      event.preventDefault();
      closeSidebarMenu();
      return;
    }

    if (event.key !== "Tab") {return;}

    const focusableElements = Array.from(
      sidebar.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])',
      ),
    ).filter(function (element) {
      return element.offsetParent !== null;
    });

    if (!focusableElements.length) {return;}

    const firstFocusable = focusableElements[0];
    const lastFocusable = focusableElements[focusableElements.length - 1];

    if (event.shiftKey && document.activeElement === firstFocusable) {
      event.preventDefault();
      lastFocusable.focus();
    } else if (!event.shiftKey && document.activeElement === lastFocusable) {
      event.preventDefault();
      firstFocusable.focus();
    }
  });

  /* =========================
     CATEGORY DROPDOWN
     ========================= */

  if (categoryToggle && categoryMenu) {
    const hasActiveSub = categoryMenu.querySelector(".active-sub");

    function syncDropdownState(isOpen) {
      categoryMenu.classList.toggle("show", isOpen);
      categoryMenu.setAttribute("aria-hidden", isOpen ? "false" : "true");
      categoryToggle.setAttribute("aria-expanded", isOpen ? "true" : "false");

      if (dropdownArrow) {
        dropdownArrow.style.transform = isOpen
          ? "rotate(180deg)"
          : "rotate(0deg)";
      }
    }

    const initiallyOpen =
      categoryMenu.classList.contains("show") || Boolean(hasActiveSub);
    syncDropdownState(initiallyOpen);

    categoryToggle.addEventListener("click", function () {
      syncDropdownState(!categoryMenu.classList.contains("show"));
    });
  }

  /* =========================
     POPUP
     ========================= */

  const popupOverlay = document.getElementById("popupOverlay");
  const popupBox = document.getElementById("popupBox");

  function closePopup() {
    if (!popupOverlay) {return;}

    if (popupBox) {
      popupBox.classList.add("popup-hide");
    }

    popupOverlay.classList.add("popup-overlay-hide");

    window.setTimeout(function () {
      if (popupOverlay.isConnected) {
        popupOverlay.remove();
      }
    }, 600);
  }

  if (popupOverlay) {
    window.setTimeout(closePopup, 7000);

    popupOverlay.addEventListener("click", closePopup);

    if (popupBox) {
      popupBox.addEventListener("click", function (event) {
        event.stopPropagation();
      });
    }
  }

  /* =========================
     PROFILE IMAGE AUTO-SUBMIT
     ========================= */

  const profileInput = document.getElementById("new_profile_image");

  const submitProfileBtn = document.getElementById("submitProfileBtn");

  if (profileInput && submitProfileBtn) {
    profileInput.addEventListener("change", function () {
      if (this.files && this.files.length > 0) {
        submitProfileBtn.click();
      }
    });
  }

  /* =========================
     BUSINESS / BRANCH WORKSPACE SWITCHER
     ========================= */

  const workspaceForm = document.querySelector("[data-workspace-switch-form]");
  const workspacePicker = workspaceForm
    ? workspaceForm.querySelector("[data-workspace-picker]")
    : null;
  const workspaceToggle = workspacePicker
    ? workspacePicker.querySelector("[data-workspace-toggle]")
    : null;
  const workspaceList = workspacePicker
    ? workspacePicker.querySelector("[data-workspace-list]")
    : null;
  const workspaceOptions = workspaceList
    ? Array.from(workspaceList.querySelectorAll("[data-workspace-option]"))
    : [];

  if (
    workspaceForm &&
    workspacePicker &&
    workspaceToggle &&
    workspaceList &&
    workspaceOptions.length > 0
  ) {
    function selectedWorkspaceIndex() {
      const selectedIndex = workspaceOptions.findIndex(function (option) {
        return option.getAttribute("aria-selected") === "true";
      });

      return selectedIndex >= 0 ? selectedIndex : 0;
    }

    function focusWorkspaceOption(index) {
      const optionCount = workspaceOptions.length;
      if (optionCount === 0) {return;}

      const safeIndex = ((index % optionCount) + optionCount) % optionCount;
      const option = workspaceOptions[safeIndex];

      option.focus({ preventScroll: true });
      option.scrollIntoView({ block: "nearest" });
    }

    function setWorkspacePickerState(isOpen, focusIndex = null) {
      workspacePicker.classList.toggle("is-open", isOpen);
      workspaceToggle.setAttribute("aria-expanded", isOpen ? "true" : "false");
      workspaceList.hidden = !isOpen;

      if (isOpen) {
        const nextIndex =
          focusIndex === null ? selectedWorkspaceIndex() : focusIndex;

        window.requestAnimationFrame(function () {
          focusWorkspaceOption(nextIndex);
        });
      }
    }

    workspaceToggle.addEventListener("click", function () {
      const shouldOpen = !workspacePicker.classList.contains("is-open");
      setWorkspacePickerState(shouldOpen);
    });

    workspaceToggle.addEventListener("keydown", function (event) {
      if (event.key === "ArrowDown" || event.key === "ArrowUp") {
        event.preventDefault();
        const selectedIndex = selectedWorkspaceIndex();
        const startIndex =
          event.key === "ArrowDown" ? selectedIndex : selectedIndex - 1;
        setWorkspacePickerState(true, startIndex);
      } else if (event.key === "Escape") {
        event.preventDefault();
        setWorkspacePickerState(false);
      }
    });

    workspaceOptions.forEach(function (option, optionIndex) {
      option.addEventListener("click", function (event) {
        if (option.getAttribute("aria-selected") === "true") {
          event.preventDefault();
          setWorkspacePickerState(false);
          workspaceToggle.focus();
        }
      });

      option.addEventListener("keydown", function (event) {
        if (event.key === "ArrowDown") {
          event.preventDefault();
          focusWorkspaceOption(optionIndex + 1);
        } else if (event.key === "ArrowUp") {
          event.preventDefault();
          focusWorkspaceOption(optionIndex - 1);
        } else if (event.key === "Home") {
          event.preventDefault();
          focusWorkspaceOption(0);
        } else if (event.key === "End") {
          event.preventDefault();
          focusWorkspaceOption(workspaceOptions.length - 1);
        } else if (event.key === "Escape") {
          event.preventDefault();
          setWorkspacePickerState(false);
          workspaceToggle.focus();
        } else if (event.key === "Tab") {
          setWorkspacePickerState(false);
        }
      });
    });

    workspaceForm.addEventListener("submit", function () {
      workspaceForm.classList.add("is-switching");
      workspaceForm.setAttribute("aria-busy", "true");
      workspaceToggle.disabled = true;
      setWorkspacePickerState(false);
    });

    document.addEventListener("click", function (event) {
      if (
        workspacePicker.classList.contains("is-open") &&
        !workspacePicker.contains(event.target)
      ) {
        setWorkspacePickerState(false);
      }
    });

    document.addEventListener("keydown", function (event) {
      if (
        event.key === "Escape" &&
        workspacePicker.classList.contains("is-open")
      ) {
        setWorkspacePickerState(false);
        workspaceToggle.focus();
      }
    });
  }
})();
