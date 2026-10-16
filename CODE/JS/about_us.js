/* ============================================================
   NexGen About Us — page-only interactions
   Shared header/sidebar/theme behavior is handled by header.js.
   ============================================================ */

document.addEventListener("DOMContentLoaded", () => {
  const animatedCards = document.querySelectorAll(".animate-card");

  if (!animatedCards.length) {
    return;
  }

  if (!("IntersectionObserver" in window)) {
    animatedCards.forEach((card) => card.classList.add("show"));
    return;
  }

  const observer = new IntersectionObserver(
    (entries, obs) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add("show");
          obs.unobserve(entry.target);
        }
      });
    },
    {
      threshold: 0.22,
    },
  );

  animatedCards.forEach((card) => {
    observer.observe(card);
  });
});
