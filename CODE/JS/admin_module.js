document.addEventListener("DOMContentLoaded", function () {
  const popupOverlay = document.getElementById("popupOverlay");
  const popupBox = document.getElementById("popupBox");

  /* ==========================================
       POPUP HANDLER
    ========================================== */
  if (popupOverlay && popupBox) {
    popupOverlay.style.display = "flex";
    popupOverlay.style.opacity = "1";
    popupOverlay.style.visibility = "visible";
    popupBox.classList.add("show-popup");

    function closePopup() {
      popupOverlay.style.opacity = "0";
      popupOverlay.style.visibility = "hidden";

      setTimeout(() => {
        popupOverlay.remove();
      }, 400);
    }

    setTimeout(closePopup, 7000);

    popupOverlay.addEventListener("click", closePopup);
  }

  /* ==========================================
       TABLE ALIGNMENT
       Automatically center Role, Status,
       Position, Action, etc.
    ========================================== */

  const centeredHeaders = [
    "ROLE",
    "REQUESTED ROLE",
    "POSITION",
    "STATUS",
    "ACCOUNT STATUS",
    "VERIFIED",
    "CREATED",
    "SUBMITTED",
    "ACTION",
    "ACTIONS",
  ];

  function normalize(text) {
    return text.trim().replace(/\s+/g, " ").toUpperCase();
  }

  function alignTables(root = document) {
    const tables = root.querySelectorAll("table");

    tables.forEach((table) => {
      const headers = table.querySelectorAll("thead th");

      headers.forEach((header, index) => {
        const title = normalize(header.innerText);

        if (!centeredHeaders.includes(title)) {return;}

        header.classList.add("admin-col-center");

        table.querySelectorAll("tbody tr").forEach((row) => {
          if (row.children[index]) {
            row.children[index].classList.add("admin-col-center");
          }
        });
      });
    });
  }

  alignTables();

  /* ==========================================
       GLOBAL TABLE 10-ROW SCROLL LIMIT
       Any admin table with more than 10 real
       data rows becomes vertically scrollable.
       The header stays visible while scrolling.
    ========================================== */

  function getRealDataRows(table) {
    const rows = Array.from(table.querySelectorAll("tbody > tr"));

    return rows.filter((row) => {
      const cells = Array.from(row.children);
      if (!cells.length) {return false;}

      // Ignore a single full-width "No records found" type row.
      if (cells.length === 1 && cells[0].hasAttribute("colspan")) {
        return false;
      }

      return true;
    });
  }

  function applyTenRowTableLimit(root = document) {
    const tables = root.querySelectorAll("table");

    tables.forEach((table) => {
      const rows = getRealDataRows(table);
      const wrap = table.closest(".table-wrap");

      if (!wrap) {return;}

      if (rows.length > 10) {
        wrap.classList.add("admin-table-scrollable");

        // Calculate the exact visible height for the header + first 10 rows.
        // This adapts automatically to tables that use different row heights.
        const thead = table.querySelector("thead");
        let visibleHeight = thead ? thead.getBoundingClientRect().height : 0;

        rows.slice(0, 10).forEach((row) => {
          visibleHeight += row.getBoundingClientRect().height;
        });

        // Small allowance for wrapper borders / horizontal scrollbar.
        visibleHeight += 12;

        if (visibleHeight > 0) {
          wrap.style.maxHeight = Math.ceil(visibleHeight) + "px";
        }

        wrap.style.overflowY = "auto";
        wrap.style.overflowX = "auto";
      } else {
        wrap.classList.remove("admin-table-scrollable");
        wrap.style.removeProperty("max-height");

        // Restore the page/module's own overflow rules for tables <= 10 rows.
        wrap.style.removeProperty("overflow-y");
        wrap.style.removeProperty("overflow-x");
      }
    });
  }

  applyTenRowTableLimit();

  let tableResizeTimer = null;
  window.addEventListener("resize", function () {
    clearTimeout(tableResizeTimer);
    tableResizeTimer = setTimeout(function () {
      applyTenRowTableLimit();
    }, 120);
  });

  /* ==========================================
       AUTO ALIGN NEW AJAX TABLES
    ========================================== */

  const observer = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      mutation.addedNodes.forEach((node) => {
        if (node.nodeType !== 1) {return;}

        if (node.matches("table")) {
          alignTables(node.parentElement);
          applyTenRowTableLimit(node.parentElement);
        } else {
          alignTables(node);
          applyTenRowTableLimit(node);
        }
      });
    });
  });

  observer.observe(document.body, {
    childList: true,
    subtree: true,
  });
});
