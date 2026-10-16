/* ============================================================
   NexGen Dashboard — dashboard-only interactions
   IMPORTANT:
   Shared sidebar, dropdown, profile, popup and theme behavior
   now belongs ONLY to /NexGen/CODE/JS/header.js.
   ============================================================ */

/* CIRCUIT PULSE BACKGROUND */
(function () {
  const container = document.querySelector(".hero-bg-circuit");
  const layer = document.getElementById("circuitPulses");
  if (!container || !layer) {return;}

  const GRID = 48;

  function spawnPulse() {
    const width = container.offsetWidth || window.innerWidth;
    const height = container.offsetHeight || window.innerHeight;
    const cols = Math.ceil(width / GRID);
    const rows = Math.ceil(height / GRID);

    const dot = document.createElement("div");
    dot.className = "circuit-pulse";

    const horizontal = Math.random() < 0.5;
    const steps = 3 + Math.floor(Math.random() * 5);
    let x, y, dx, dy;

    if (horizontal) {
      y = Math.floor(Math.random() * rows) * GRID;
      x = Math.floor(Math.random() * Math.max(1, cols - steps)) * GRID;
      dx = steps * GRID;
      dy = 0;
    } else {
      x = Math.floor(Math.random() * cols) * GRID;
      y = Math.floor(Math.random() * Math.max(1, rows - steps)) * GRID;
      dx = 0;
      dy = steps * GRID;
    }

    dot.style.left = `${x}px`;
    dot.style.top = `${y}px`;

    const duration = steps * 0.6;
    dot.style.animationDuration = `${duration}s`;
    layer.appendChild(dot);

    if (dot.animate) {
      dot.animate(
        [
          { transform: "translate(0,0)" },
          { transform: `translate(${dx}px,${dy}px)` },
        ],
        { duration: duration * 1000, easing: "linear" },
      );
    }

    setTimeout(() => dot.remove(), duration * 1000);
  }

  const reduceMotion =
    window.matchMedia &&
    window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  if (reduceMotion) {return;}

  for (let i = 0; i < 14; i++) {
    setTimeout(spawnPulse, i * 150);
  }

  setInterval(spawnPulse, 350);
})();

/* HERO BUTTON SCROLL */
const openModulesBtn = document.querySelector('a[href="#module-section"]');
const moduleSection = document.getElementById("module-section");

if (openModulesBtn && moduleSection) {
  openModulesBtn.addEventListener("click", (e) => {
    e.preventDefault();
    moduleSection.scrollIntoView({
      behavior: "smooth",
      block: "start",
    });
  });
}

/* HERO MOVES UP ON SCROLL */
const heroShell = document.getElementById("heroShell");
const topVideoArea = document.getElementById("topVideoArea");

function animateHeroOnScroll() {
  if (!heroShell || !topVideoArea) {return;}

  const areaRect = topVideoArea.getBoundingClientRect();
  const viewportHeight = window.innerHeight;

  if (areaRect.bottom > 0 && areaRect.top < viewportHeight) {
    const scrolled = Math.max(0, -areaRect.top);
    const moveY = Math.min(scrolled * 0.18, 90);
    heroShell.style.transform = `translateY(-${moveY}px)`;
  } else {
    heroShell.style.transform = `translateY(0px)`;
  }
}

/* MODULES REVEAL EVERY TIME */
const moduleRevealItems = document.querySelectorAll(".module-reveal");

function animateModulesOnScroll() {
  if (!moduleRevealItems.length) {return;}

  const triggerPoint = window.innerHeight * 0.85;

  moduleRevealItems.forEach((item) => {
    const rect = item.getBoundingClientRect();

    if (rect.top < triggerPoint && rect.bottom > 80) {
      item.classList.add("show");
    } else {
      item.classList.remove("show");
    }
  });
}

function runScrollAnimations() {
  animateHeroOnScroll();
  animateModulesOnScroll();
}

window.addEventListener("scroll", runScrollAnimations);
window.addEventListener("load", runScrollAnimations);
window.addEventListener("resize", runScrollAnimations);

/* WHY US SECTION - SHOW/HIDE ON DEMAND */
const whySectionToggleBtn = document.getElementById("whySectionToggleBtn");
const whySection = document.getElementById("why-section");
const whyRevealItems = document.querySelectorAll(".why-reveal");

if (whySectionToggleBtn && whySection) {
  whySectionToggleBtn.addEventListener("click", () => {
    const isOpen = whySection.classList.toggle("show");
    whySectionToggleBtn.classList.toggle("open", isOpen);
    whySectionToggleBtn.setAttribute(
      "aria-expanded",
      isOpen ? "true" : "false",
    );

    const label = whySectionToggleBtn.querySelector("span");
    if (label) {
      label.textContent = isOpen ? "Hide" : "Why NextGen?";
    }

    if (isOpen) {
      whyRevealItems.forEach((item) => item.classList.add("show"));

      setTimeout(() => {
        whySection.scrollIntoView({ behavior: "smooth", block: "start" });
      }, 120);
    } else {
      whyRevealItems.forEach((item) => item.classList.remove("show"));
    }
  });
}

/* WHY US - EXPAND/COLLAPSE EXTRA ABOUT TEXT */
const whyToggleBtn = document.getElementById("whyToggleBtn");
const whyExtra = document.getElementById("whyExtra");

if (whyToggleBtn && whyExtra) {
  whyToggleBtn.addEventListener("click", () => {
    const isOpen = whyExtra.classList.toggle("show");
    whyToggleBtn.classList.toggle("open", isOpen);
    whyToggleBtn.setAttribute("aria-expanded", isOpen ? "true" : "false");

    const label = whyToggleBtn.querySelector("span");
    if (label) {
      label.textContent = isOpen ? "Show Less" : "More About Us";
    }
  });
}
