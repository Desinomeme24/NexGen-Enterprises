/* =========================
   POPUP (success / error banner)
========================= */
const popupOverlay = document.getElementById("popupOverlay");

if (popupOverlay) {
  setTimeout(() => {
    popupOverlay.remove();
  }, 7000);

  popupOverlay.addEventListener("click", () => {
    popupOverlay.remove();
  });
}

/* =========================
   THEME (light / dark)
   Shares the "nexgen-theme" key with header.js so the choice made
   here stays in sync on every page that has the topbar toggle.
========================= */
const htmlEl = document.documentElement;
const themeOptions = document.querySelectorAll(".theme-card");

function markActiveTheme(theme) {
  themeOptions.forEach((btn) => {
    btn.classList.toggle("selected", btn.dataset.themeChoice === theme);
  });
}

markActiveTheme(htmlEl.getAttribute("data-theme") || "dark");

themeOptions.forEach((btn) => {
  btn.addEventListener("click", function () {
    const theme = this.dataset.themeChoice;
    htmlEl.setAttribute("data-theme", theme);
    try {
      localStorage.setItem("nexgen-theme", theme);
    } catch (error) {
      console.warn("Could not save theme preference:", error);
    }
    markActiveTheme(theme);
  });
});

/* =========================
   NAV / PANEL SWITCHING (Account, Businesses, Security, Personalization)
========================= */
const navItems = document.querySelectorAll(".settings-nav .nav-item");
const panels = document.querySelectorAll(".settings-panel");
const segmentedBtns = document.querySelectorAll(".segmented-btn");
const securityMethods = document.querySelectorAll(".security-method");

const directPasswordForm = document.getElementById("directPasswordForm");
const otpRequestForm = document.getElementById("otpRequestForm");
const otpVerifyForm = document.getElementById("otpVerifyForm");
const workspaceSwitchForm = document.getElementById("workspaceSwitchForm");
const addBusinessForm = document.getElementById("addBusinessForm");
const addBranchForm = document.getElementById("addBranchForm");

const profileInput = document.getElementById("new_profile_image");
const profileForm = document.getElementById("profileImageForm");

function openPanel(panelId) {
  navItems.forEach((item) => {
    item.classList.toggle("active", item.dataset.target === panelId);
  });

  panels.forEach((panel) => {
    panel.classList.toggle("active-panel", panel.id === panelId);
  });

  try {
    localStorage.setItem("settingsActivePanel", panelId);
  } catch (error) {
    console.warn("Could not save active panel preference:", error);
  }
}

function openSecurityMethod(methodId) {
  securityMethods.forEach((method) => {
    method.classList.remove("active-method");
  });

  segmentedBtns.forEach((btn) => {
    btn.classList.toggle("active", btn.dataset.method === methodId);
  });

  if (methodId) {
    const selectedMethod = document.getElementById(methodId);
    if (selectedMethod) {
      selectedMethod.classList.add("active-method");
      try {
        localStorage.setItem("settingsSecurityMethod", methodId);
      } catch (error) {
        console.warn("Could not save security method preference:", error);
      }
    }
  }
}

navItems.forEach((item) => {
  item.addEventListener("click", function () {
    openPanel(this.dataset.target);
  });
});

segmentedBtns.forEach((btn) => {
  btn.addEventListener("click", function () {
    openSecurityMethod(this.dataset.method);
    openPanel("security-panel");
  });
});

[workspaceSwitchForm, addBusinessForm, addBranchForm].forEach((form) => {
  if (!form) {return;}

  form.addEventListener("submit", function () {
    try {
      localStorage.setItem("settingsActivePanel", "workspace-panel");
    } catch (error) {
      console.warn("Could not save panel preference:", error);
    }
  });
});

if (directPasswordForm) {
  directPasswordForm.addEventListener("submit", function () {
    try {
      localStorage.setItem("settingsActivePanel", "security-panel");
      localStorage.setItem("settingsSecurityMethod", "current-password-method");
    } catch (error) {
      console.warn("Could not save security preferences:", error);
    }
  });
}

if (otpRequestForm) {
  otpRequestForm.addEventListener("submit", function () {
    try {
      localStorage.setItem("settingsActivePanel", "security-panel");
      localStorage.setItem("settingsSecurityMethod", "otp-method");
    } catch (error) {
      console.warn("Could not save security preferences:", error);
    }
  });
}

if (otpVerifyForm) {
  otpVerifyForm.addEventListener("submit", function () {
    try {
      localStorage.setItem("settingsActivePanel", "security-panel");
      localStorage.setItem("settingsSecurityMethod", "otp-method");
    } catch (error) {
      console.warn("Could not save security preferences:", error);
    }
  });
}

const requestedPanel = new URLSearchParams(window.location.search).get("panel");
const requestedPanelExists = requestedPanel
  ? Boolean(document.getElementById(requestedPanel))
  : false;

let savedPanel = "account-panel";
let savedMethod = "";

try {
  savedPanel = requestedPanelExists
    ? requestedPanel
    : localStorage.getItem("settingsActivePanel") || "account-panel";
  savedMethod = localStorage.getItem("settingsSecurityMethod") || "";
} catch (error) {
  console.warn("Could not read storage preferences:", error);
}

openPanel(savedPanel);

if (savedPanel === "security-panel" && savedMethod) {
  openSecurityMethod(savedMethod);
}

if (profileInput && profileForm) {
  profileInput.addEventListener("change", function () {
    if (this.files && this.files.length > 0) {
      profileForm.submit();
    }
  });
}
