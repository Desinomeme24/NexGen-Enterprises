const sidebar = document.getElementById("sidebar");
const openSidebar = document.getElementById("openSidebar");
const closeSidebar = document.getElementById("closeSidebar");
const overlay = document.getElementById("overlay");

const categoryToggle = document.getElementById("categoryToggle");
const categoryMenu = document.getElementById("categoryMenu");
const dropdownArrow = document.getElementById("dropdownArrow");

const productModal = document.getElementById("productModal");
const openProductModal = document.getElementById("openProductModal");
const closeProductModal = document.getElementById("closeProductModal");

const editProductModal = document.getElementById("editProductModal");
const closeEditProductModal = document.getElementById("closeEditProductModal");

const stockModal = document.getElementById("stockModal");
const closeStockModal = document.getElementById("closeStockModal");

const receiveShipmentModal = document.getElementById("receiveShipmentModal");
const closeReceiveShipmentModal = document.getElementById(
  "closeReceiveShipmentModal",
);

const placeOrderModal = document.getElementById("placeOrderModal");
const closePlaceOrderModal = document.getElementById("closePlaceOrderModal");

const categoryModal = document.getElementById("categoryModal");
const openCategoryModal = document.getElementById("openCategoryModal");
const closeCategoryModal = document.getElementById("closeCategoryModal");

const batchesModal = document.getElementById("batchesModal");
const closeBatchesModal = document.getElementById("closeBatchesModal");
const batchesModalBody = document.getElementById("batchesModalBody");
const batchesProductName = document.getElementById("batchesProductName");
const batchDropForm = document.getElementById("batchDropForm");
const batchDropId = document.getElementById("batchDropId");

const dropBatchConfirmOverlay = document.getElementById(
  "dropBatchConfirmOverlay",
);
const dropBatchConfirmNumber = document.getElementById(
  "dropBatchConfirmNumber",
);
const dropBatchCancelBtn = document.getElementById("dropBatchCancelBtn");
const dropBatchConfirmBtn = document.getElementById("dropBatchConfirmBtn");
let pendingDropBatchId = null;

const productImageInput = document.getElementById("product_image");
const previewImage = document.getElementById("previewImage");

const WIZARD_TOTAL_STEPS = 4;

const editProductImageInput = document.getElementById("edit_product_image");
const editPreviewImage = document.getElementById("editPreviewImage");

const stockMovementType = document.getElementById("stock_movement_type");
const onOrderOwnerFields = document.getElementById("onOrderOwnerFields");
const deductOnOrderWrap = document.getElementById("deductOnOrderWrap");

const filterToggleBtn = document.getElementById("toggleFilterTool");
const floatingFilterBox = document.getElementById("floatingFilterBox");
const clearFiltersBtn = document.getElementById("clearFiltersBtn");

let activeRequestController = null;

function getDynamicArea() {
  return document.getElementById("inventoryDynamicArea");
}

function getFilterForm() {
  return document.getElementById("inventoryFilterForm");
}

function getSearchInput() {
  return document.getElementById("inventorySearchInput");
}

function getCategoryFilter() {
  return document.getElementById("inventoryCategoryFilter");
}

function getStatusFilter() {
  return document.getElementById("inventoryStatusFilter");
}

function openSidebarMenu() {
  if (sidebar) {sidebar.classList.add("active");}
  if (overlay) {overlay.classList.add("show");}
  document.body.style.overflow = "hidden";
}

function closeSidebarMenu() {
  if (sidebar) {sidebar.classList.remove("active");}
  if (overlay) {overlay.classList.remove("show");}
  document.body.style.overflow = "";
}

window.openSidebarMenu = openSidebarMenu;
window.closeSidebarMenu = closeSidebarMenu;

function openModal(modal) {
  if (!modal) {return;}
  modal.classList.add("show");
  document.body.style.overflow = "hidden";
}

function closeModal(modal) {
  if (!modal) {return;}
  modal.classList.remove("show");
  document.body.style.overflow = "";
}

/* PRODUCT WIZARDS (Add + Edit share the same step markup/classes) */
function setupProductWizard(config) {
  const form = document.getElementById(config.formId);
  if (!form) {return null;}

  const stepper = document.getElementById(config.stepperId);
  const backBtn = document.getElementById(config.backBtnId);
  const nextBtn = document.getElementById(config.nextBtnId);
  const submitBtn = document.getElementById(config.submitBtnId);
  const stepNumEl = document.getElementById(config.stepNumId);
  const dotsEl = document.getElementById(config.dotsId);
  const totalSteps = config.totalSteps || WIZARD_TOTAL_STEPS;

  let currentStep = 1;

  function getStepPanel(step) {
    return form.querySelector('.wizard-step[data-step="' + step + '"]');
  }

  function updateUI() {
    form.querySelectorAll(".wizard-step").forEach((panel) => {
      const step = parseInt(panel.dataset.step, 10);
      panel.classList.toggle("is-active", step === currentStep);
    });

    if (stepper) {
      stepper.querySelectorAll(".step-node").forEach((node) => {
        const step = parseInt(node.dataset.step, 10);
        node.classList.toggle("is-active", step === currentStep);
        node.classList.toggle("is-complete", step < currentStep);
      });

      stepper.querySelectorAll(".step-line").forEach((line, index) => {
        line.classList.toggle("is-complete", index + 1 < currentStep);
      });
    }

    if (dotsEl) {
      dotsEl.querySelectorAll(".step-dot").forEach((dot) => {
        const step = parseInt(dot.dataset.dot, 10);
        dot.classList.toggle("is-active", step === currentStep);
        dot.classList.toggle("is-complete", step < currentStep);
      });
    }

    if (stepNumEl) {stepNumEl.textContent = currentStep;}
    if (backBtn) {backBtn.classList.toggle("is-visible", currentStep > 1);}
    if (nextBtn) {
      nextBtn.classList.toggle("is-visible", currentStep < totalSteps);
    }
    if (submitBtn) {
      submitBtn.classList.toggle("is-visible", currentStep === totalSteps);
    }
  }

  function validateStep(step) {
    const panel = getStepPanel(step);
    if (!panel) {return true;}

    const fields = panel.querySelectorAll("input, select, textarea");
    for (const field of fields) {
      if (!field.checkValidity()) {
        field.reportValidity();
        return false;
      }
    }
    return true;
  }

  function goToStep(step) {
    currentStep = Math.min(Math.max(step, 1), totalSteps);
    updateUI();
  }

  function reset() {
    currentStep = 1;
    updateUI();
  }

  if (nextBtn) {
    nextBtn.addEventListener("click", () => {
      if (!validateStep(currentStep)) {return;}
      goToStep(currentStep + 1);
    });
  }

  if (backBtn) {
    backBtn.addEventListener("click", () => goToStep(currentStep - 1));
  }

  if (stepper) {
    stepper.querySelectorAll(".step-node").forEach((node) => {
      node.addEventListener("click", () => {
        const targetStep = parseInt(node.dataset.step, 10);

        if (targetStep <= currentStep) {
          goToStep(targetStep);
          return;
        }

        for (let step = currentStep; step < targetStep; step++) {
          if (!validateStep(step)) {return;}
        }

        goToStep(targetStep);
      });
    });
  }

  form.addEventListener("submit", (event) => {
    if (!validateStep(totalSteps)) {
      event.preventDefault();
    }
  });

  updateUI();

  return { reset };
}

const addProductWizard = setupProductWizard({
  formId: "addProductWizardForm",
  stepperId: "productWizardStepper",
  backBtnId: "wizardBackBtn",
  nextBtnId: "wizardNextBtn",
  submitBtnId: "wizardSubmitBtn",
  stepNumId: "wizardCurrentStepNum",
  dotsId: "wizardStepDots",
});

const editProductWizard = setupProductWizard({
  formId: "editProductWizardForm",
  stepperId: "editProductWizardStepper",
  backBtnId: "editWizardBackBtn",
  nextBtnId: "editWizardNextBtn",
  submitBtnId: "editWizardSubmitBtn",
  stepNumId: "editWizardCurrentStepNum",
  dotsId: "editWizardStepDots",
});

function closeAllMenus(exceptMenu = null) {
  document.querySelectorAll("[data-action-menu].show").forEach((menu) => {
    if (menu !== exceptMenu) {
      menu.classList.remove("show");
    }
  });
}

let actionMenuAutoId = 0;

// The dropdown menus start out nested inside .inventory-table-shell, which
// uses overflow: hidden to keep its rounded corners clean. Browsers clip
// position: fixed descendants to an overflow:hidden ancestor's box too —
// it's not just a coordinate calculation, the paint itself gets cut off —
// so a menu that needs to render below that card is silently hidden even
// though its on-screen coordinates are correct. Moving each menu to be a
// direct child of <body> removes it from that clipped ancestor entirely,
// while the button that opens it keeps a matching ID to find it again.
function escapeOverflowForActionMenus() {
  document
    .querySelectorAll("body > [data-menu-id]")
    .forEach((el) => el.remove());

  document.querySelectorAll(".action-menu-wrap").forEach((wrap) => {
    const menu = wrap.querySelector("[data-action-menu]");
    if (!menu) {return;}

    actionMenuAutoId += 1;
    const menuId = "action-menu-" + actionMenuAutoId;

    menu.dataset.menuId = menuId;
    wrap.dataset.menuFor = menuId;

    document.body.appendChild(menu);
  });
}

function positionActionMenu(button, menu) {
  const gap = 10;
  const edgePadding = 8;

  const btnRect = button.getBoundingClientRect();

  // Measure the menu off-screen first so width/height are accurate
  // before we calculate where it should sit.
  menu.style.visibility = "hidden";
  menu.style.display = "block";
  const menuRect = menu.getBoundingClientRect();
  menu.style.display = "";
  menu.style.visibility = "";

  // Preferred: to the left of the button.
  let left = btnRect.left - menuRect.width - gap;

  // Not enough room on the left? Open to the right instead.
  if (left < edgePadding) {
    left = btnRect.right + gap;
  }

  // Clamp so it never runs off either edge of the viewport.
  const maxLeft = window.innerWidth - menuRect.width - edgePadding;
  if (left > maxLeft) {left = maxLeft;}
  if (left < edgePadding) {left = edgePadding;}

  // Anchor the menu to the button instead of centering on it, so it
  // consistently opens in the same spot relative to whichever row was
  // clicked. Prefer dropping down from the button's top edge; only flip
  // upward (aligning the menu's bottom with the button's bottom) if there
  // genuinely isn't enough room below.
  const spaceBelow = window.innerHeight - btnRect.top - edgePadding;
  let top = btnRect.top;

  if (menuRect.height > spaceBelow) {
    top = btnRect.bottom - menuRect.height;
  }

  const maxTop = window.innerHeight - menuRect.height - edgePadding;
  if (top > maxTop) {top = maxTop;}
  if (top < edgePadding) {top = edgePadding;}

  menu.style.top = `${top}px`;
  menu.style.left = `${left}px`;
}

function closeFloatingTools(except = null) {
  if (floatingFilterBox && floatingFilterBox !== except) {
    floatingFilterBox.classList.remove("show");
    if (filterToggleBtn) {filterToggleBtn.setAttribute("aria-expanded", "false");}
  }
}

function setValue(id, value) {
  const element = document.getElementById(id);
  if (element) {
    element.value = value ?? "";
  }
}

function toggleStockOrderFields() {
  if (!stockMovementType) {return;}

  const movement = stockMovementType.value;

  if (onOrderOwnerFields) {
    onOrderOwnerFields.style.display = "block";
  }

  if (deductOnOrderWrap) {
    deductOnOrderWrap.style.display =
      movement === "stock_in" ? "block" : "none";
  }
}

function showDynamicLoading() {
  const dynamicArea = getDynamicArea();
  if (!dynamicArea) {return;}
  dynamicArea.classList.add("is-loading");
}

function hideDynamicLoading() {
  const dynamicArea = getDynamicArea();
  if (!dynamicArea) {return;}
  dynamicArea.classList.remove("is-loading");
}

function syncBrowserUrl(url) {
  window.history.replaceState({}, "", url);
}

async function refreshInventoryContent(url) {
  const dynamicArea = getDynamicArea();
  if (!dynamicArea) {
    window.location.href = url;
    return;
  }

  if (activeRequestController) {
    activeRequestController.abort();
  }

  activeRequestController = new AbortController();
  showDynamicLoading();
  closeAllMenus();

  try {
    const response = await fetch(url, {
      method: "GET",
      headers: {
        "X-Requested-With": "XMLHttpRequest",
      },
      signal: activeRequestController.signal,
    });

    if (!response.ok) {
      throw new Error("Failed to refresh inventory content.");
    }

    const html = await response.text();
    const parser = new DOMParser();
    const nextDocument = parser.parseFromString(html, "text/html");
    const nextDynamicArea = nextDocument.getElementById("inventoryDynamicArea");

    if (!nextDynamicArea) {
      window.location.href = url;
      return;
    }

    dynamicArea.innerHTML = nextDynamicArea.innerHTML;
    syncBrowserUrl(url);
    initDynamicFilterControls();
    escapeOverflowForActionMenus();
  } catch (error) {
    if (error.name !== "AbortError") {
      window.location.href = url;
    }
  } finally {
    hideDynamicLoading();
  }
}

function submitFiltersInstantly() {
  const filterForm = getFilterForm();
  if (!filterForm) {return;}

  const formData = new FormData(filterForm);
  const query = new URLSearchParams();

  for (const [key, value] of formData.entries()) {
    if (String(value).trim() !== "" && String(value) !== "0") {
      query.set(key, value);
    }
  }

  const queryString = query.toString();
  const url = queryString
    ? `${filterForm.action}?${queryString}`
    : filterForm.action;

  refreshInventoryContent(url);
}

function initDynamicFilterControls() {
  const inventorySearchInput = getSearchInput();
  const inventoryCategoryFilter = getCategoryFilter();
  const inventoryStatusFilter = getStatusFilter();

  if (inventorySearchInput && !inventorySearchInput.dataset.enterBound) {
    inventorySearchInput.dataset.enterBound = "1";

    inventorySearchInput.addEventListener("keydown", (event) => {
      if (event.key === "Enter") {
        event.preventDefault();
        submitFiltersInstantly();
      }
    });
  }

  if (
    inventoryCategoryFilter &&
    !inventoryCategoryFilter.dataset.instantBound
  ) {
    inventoryCategoryFilter.dataset.instantBound = "1";
    inventoryCategoryFilter.addEventListener("change", () => {
      submitFiltersInstantly();
    });
  }

  if (inventoryStatusFilter && !inventoryStatusFilter.dataset.instantBound) {
    inventoryStatusFilter.dataset.instantBound = "1";
    inventoryStatusFilter.addEventListener("change", () => {
      submitFiltersInstantly();
    });
  }
}

/* SIDEBAR */
if (openSidebar) {
  openSidebar.addEventListener("click", openSidebarMenu);
}

if (closeSidebar) {
  closeSidebar.addEventListener("click", closeSidebarMenu);
}

if (overlay) {
  overlay.addEventListener("click", closeSidebarMenu);
}

/* DROPDOWN IN SIDEBAR */
if (categoryToggle && categoryMenu) {
  const hasActiveSub = categoryMenu.querySelector(
    ".active-sub, .active-submenu",
  );

  if (hasActiveSub) {
    categoryMenu.classList.add("show");
    if (dropdownArrow) {dropdownArrow.style.transform = "rotate(180deg)";}
  }

  categoryToggle.addEventListener("click", () => {
    categoryMenu.classList.toggle("show");
    if (dropdownArrow) {
      dropdownArrow.style.transform = categoryMenu.classList.contains("show")
        ? "rotate(180deg)"
        : "rotate(0deg)";
    }
  });
}

/* OPEN / CLOSE MODALS */
if (openProductModal) {
  openProductModal.addEventListener("click", () => {
    if (addProductWizard) {addProductWizard.reset();}
    openModal(productModal);
  });
}

if (closeProductModal) {
  closeProductModal.addEventListener("click", () => {
    closeModal(productModal);
    if (addProductWizard) {addProductWizard.reset();}
  });
}

if (closeEditProductModal) {
  closeEditProductModal.addEventListener("click", () => {
    closeModal(editProductModal);
    if (editProductWizard) {editProductWizard.reset();}
  });
}

if (openCategoryModal) {
  openCategoryModal.addEventListener("click", () => openModal(categoryModal));
}

if (closeCategoryModal) {
  closeCategoryModal.addEventListener("click", () => closeModal(categoryModal));
}

if (closeStockModal && stockModal) {
  closeStockModal.addEventListener("click", function (e) {
    e.preventDefault();
    e.stopPropagation();
    closeModal(stockModal);
  });
}

if (closeReceiveShipmentModal && receiveShipmentModal) {
  closeReceiveShipmentModal.addEventListener("click", function (e) {
    e.preventDefault();
    e.stopPropagation();
    closeModal(receiveShipmentModal);
  });
}

if (closePlaceOrderModal && placeOrderModal) {
  closePlaceOrderModal.addEventListener("click", function (e) {
    e.preventDefault();
    e.stopPropagation();
    closeModal(placeOrderModal);
  });
}

if (closeBatchesModal && batchesModal) {
  closeBatchesModal.addEventListener("click", function (e) {
    e.preventDefault();
    e.stopPropagation();
    closeModal(batchesModal);
  });
}

/* PRODUCT BATCHES: view a product's individual batches, drop expired ones */
function escapeHtml(value) {
  return String(value === null || value === undefined ? "" : value).replace(
    /[&<>"']/g,
    (ch) =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[
        ch
      ],
  );
}

function renderBatchesTable(batches) {
  if (!batchesModalBody) {return;}

  if (!batches.length) {
    batchesModalBody.innerHTML =
      '<tr><td colspan="5" class="batches-empty-state">No batches recorded for this product yet.</td></tr>';
    return;
  }

  batchesModalBody.innerHTML = batches
    .map((batch) => {
      let statusLabel = "Valid";
      let statusClass = "batch-valid";
      if (batch.status === "dropped") {
        statusLabel = "Dropped";
        statusClass = "batch-dropped";
      } else if (batch.is_expired) {
        statusLabel = "Expired";
        statusClass = "batch-expired";
      }

      const expiryText = batch.expiry_date
        ? new Date(batch.expiry_date + "T00:00:00").toLocaleDateString(
            "en-US",
            { year: "numeric", month: "short", day: "numeric" },
          )
        : "No expiry";

      const safeBatchNumber = escapeHtml(batch.batch_number);
      const dropCell = batch.can_drop
        ? `<button type="button" class="batch-drop-btn" data-drop-batch-id="${batch.id}" data-drop-batch-number="${safeBatchNumber}">Drop</button>`
        : "";

      return `<tr>
        <td>${safeBatchNumber}</td>
        <td>${escapeHtml(batch.quantity)}</td>
        <td>${expiryText}</td>
        <td><span class="batch-status-pill ${statusClass}">${statusLabel}</span></td>
        <td>${dropCell}</td>
      </tr>`;
    })
    .join("");
}

function openBatchesModal(productId, productName) {
  if (batchesProductName)
    {batchesProductName.textContent = productName || "Product";}
  if (batchesModalBody) {
    batchesModalBody.innerHTML =
      '<tr><td colspan="5" class="batches-empty-state">Loading...</td></tr>';
  }
  closeAllMenus();
  openModal(batchesModal);

  fetch(
    `inventory_batches.php?product_id=${encodeURIComponent(productId)}`,
    { headers: { "X-Requested-With": "XMLHttpRequest" } },
  )
    .then((res) => {
      if (!res.ok) {
        throw new Error(`HTTP error! status: ${res.status}`);
      }
      return res.json();
    })
    .then((data) => {
      if (!data.success) {
        if (batchesModalBody) {
          batchesModalBody.innerHTML = `<tr><td colspan="5" class="batches-empty-state">${data.message || "Failed to load batches."}</td></tr>`;
        }
        return;
      }
      renderBatchesTable(data.batches || []);
    })
    .catch((error) => {
      console.error("Failed to load batches:", error);
      if (batchesModalBody) {
        batchesModalBody.innerHTML =
          '<tr><td colspan="5" class="batches-empty-state">Failed to load batches. Please try again.</td></tr>';
      }
    });
}

function openDropBatchConfirm(batchId, batchNumber) {
  if (!dropBatchConfirmOverlay) {return;}
  pendingDropBatchId = batchId;
  if (dropBatchConfirmNumber) {dropBatchConfirmNumber.textContent = batchNumber;}
  dropBatchConfirmOverlay.classList.add("show");
}

function closeDropBatchConfirm() {
  if (!dropBatchConfirmOverlay) {return;}
  pendingDropBatchId = null;
  dropBatchConfirmOverlay.classList.remove("show");
}

if (dropBatchCancelBtn) {
  dropBatchCancelBtn.addEventListener("click", closeDropBatchConfirm);
}

if (dropBatchConfirmOverlay) {
  dropBatchConfirmOverlay.addEventListener("click", function (e) {
    if (e.target === dropBatchConfirmOverlay) {closeDropBatchConfirm();}
  });
}

document.addEventListener("keydown", function (e) {
  if (
    e.key === "Escape" &&
    dropBatchConfirmOverlay &&
    dropBatchConfirmOverlay.classList.contains("show")
  ) {
    closeDropBatchConfirm();
  }
});

if (dropBatchConfirmBtn) {
  dropBatchConfirmBtn.addEventListener("click", function () {
    if (pendingDropBatchId && batchDropForm && batchDropId) {
      batchDropId.value = pendingDropBatchId;
      batchDropForm.submit();
    }
  });
}

[
  productModal,
  editProductModal,
  stockModal,
  categoryModal,
  receiveShipmentModal,
  placeOrderModal,
  batchesModal,
].forEach((modal) => {
  if (!modal) {return;}

  modal.addEventListener("click", (event) => {
    if (event.target === modal) {
      closeModal(modal);
      if (modal === productModal && addProductWizard) {
        addProductWizard.reset();
      } else if (modal === editProductModal && editProductWizard) {
        editProductWizard.reset();
      }
    }
  });
});

/* FLOATING FILTER PANEL */
if (filterToggleBtn && floatingFilterBox) {
  filterToggleBtn.addEventListener("click", (event) => {
    event.stopPropagation();
    const isOpen = floatingFilterBox.classList.contains("show");
    closeFloatingTools();
    if (!isOpen) {
      floatingFilterBox.classList.add("show");
      filterToggleBtn.setAttribute("aria-expanded", "true");
    }
  });
}

if (clearFiltersBtn) {
  clearFiltersBtn.addEventListener("click", () => {
    setValue("inventorySearchInput", "");
    setValue("inventoryCategoryFilter", "0");
    setValue("inventoryStatusFilter", "");

    document.querySelectorAll(".filter-chip").forEach((chip) => {
      const isAllCategory =
        chip.dataset.filterKind === "category" &&
        chip.dataset.filterValue === "0";
      const isAllStatus =
        chip.dataset.filterKind === "status" && chip.dataset.filterValue === "";
      chip.classList.toggle("active", isAllCategory || isAllStatus);
    });

    closeFloatingTools();
    submitFiltersInstantly();
  });
}

/* IMAGE PREVIEW */
if (productImageInput && previewImage) {
  productImageInput.addEventListener("change", function () {
    const file = this.files[0];
    if (file) {
      previewImage.src = URL.createObjectURL(file);
    }
  });
}

if (editProductImageInput && editPreviewImage) {
  editProductImageInput.addEventListener("change", function () {
    const file = this.files[0];
    if (file) {
      editPreviewImage.src = URL.createObjectURL(file);
    }
  });
}

/* PURCHASE ORDER HISTORY MODAL
   Delegated on document (not bound to the trigger button directly) because
   the button lives inside #inventoryDynamicArea, which gets replaced by
   refreshInventoryContent() when switching between "Show All Products" and
   "Show Top 10 Products" - a direct listener on the old button node would be
   lost after that swap. */
function openPoHistoryModal() {
  const poModal = document.getElementById("poHistoryModal");
  if (!poModal) {return;}
  poModal.classList.add("show");
  poModal.setAttribute("aria-hidden", "false");
  document.body.style.overflow = "hidden";
}

function closePoHistoryModal() {
  const poModal = document.getElementById("poHistoryModal");
  if (!poModal) {return;}
  poModal.classList.remove("show");
  poModal.setAttribute("aria-hidden", "true");
  document.body.style.overflow = "";
}

document.addEventListener("keydown", (event) => {
  if (event.key !== "Escape") {return;}
  const poModal = document.getElementById("poHistoryModal");
  if (poModal && poModal.classList.contains("show")) {
    closePoHistoryModal();
  }
});

/* GLOBAL CLICK HANDLER */
document.addEventListener("click", (event) => {
  const filterLink = event.target.closest("[data-filter-link]");
  if (filterLink) {
    event.preventDefault();
    refreshInventoryContent(filterLink.href);
    return;
  }

  if (event.target.closest("#openPoHistoryModal")) {
    openPoHistoryModal();
    return;
  }

  if (event.target.closest("#closePoHistoryModal")) {
    closePoHistoryModal();
    return;
  }

  if (event.target.id === "poHistoryModal") {
    closePoHistoryModal();
    return;
  }

  const filterChip = event.target.closest(".filter-chip");
  if (filterChip) {
    const kind = filterChip.dataset.filterKind;
    const value = filterChip.dataset.filterValue;

    if (kind === "category") {
      setValue("inventoryCategoryFilter", value);
    } else if (kind === "status") {
      setValue("inventoryStatusFilter", value);
    }

    const chipList = filterChip.closest(".filter-chip-list");
    if (chipList) {
      chipList.querySelectorAll(".filter-chip").forEach((chip) => {
        chip.classList.toggle("active", chip === filterChip);
      });
    }

    closeFloatingTools();
    submitFiltersInstantly();
    return;
  }

  const editButton = event.target.closest(".edit-btn");
  if (editButton) {
    setValue("edit_id", editButton.dataset.id);
    setValue("edit_product_code", editButton.dataset.code);
    setValue("edit_product_name", editButton.dataset.name);
    setValue("edit_category_id", editButton.dataset.category);
    setValue("edit_brand", editButton.dataset.brand);
    setValue("edit_unit", editButton.dataset.unit);
    setValue("edit_cost_price", editButton.dataset.cost);
    setValue("edit_selling_price", editButton.dataset.selling);
    setValue("edit_discount_percent", editButton.dataset.discount);
    setValue("edit_stock_quantity", editButton.dataset.stock);
    setValue("edit_reorder_level", editButton.dataset.reorder);
    setValue("edit_on_order_level", editButton.dataset.onorder);
    setValue("edit_expiry_date", editButton.dataset.expiry);
    setValue("edit_description", editButton.dataset.description);
    setValue("edit_is_active", editButton.dataset.active);
    setValue("edit_old_image", editButton.dataset.image);

    if (editPreviewImage) {
      editPreviewImage.src = editButton.dataset.image
        ? editButton.dataset.image
        : "../../IMAGES/default-product.svg";
    }

    closeAllMenus();
    if (editProductWizard) {editProductWizard.reset();}
    openModal(editProductModal);
    return;
  }

  const stockButton = event.target.closest(".stock-btn");
  if (stockButton) {
    const productId = document.getElementById("stock_product_id");
    const productName = document.getElementById("stock_product_name");
    const currentOnOrder = document.getElementById("stock_current_on_order");
    const onOrderAdd = document.getElementById("on_order_add");
    const deductCheckbox = document.getElementById("deduct_from_on_order");

    if (productId) {productId.value = stockButton.dataset.stockId;}
    if (productName) {productName.value = stockButton.dataset.stockName;}
    if (currentOnOrder)
      {currentOnOrder.value = stockButton.dataset.currentOnorder || "0";}
    if (onOrderAdd) {onOrderAdd.value = "0";}
    if (deductCheckbox) {deductCheckbox.checked = false;}

    toggleStockOrderFields();
    closeAllMenus();
    openModal(stockModal);
    return;
  }

  const batchesButton = event.target.closest(".batches-btn");
  if (batchesButton) {
    openBatchesModal(
      batchesButton.dataset.batchesId,
      batchesButton.dataset.batchesName,
    );
    return;
  }

  const dropBatchButton = event.target.closest("[data-drop-batch-id]");
  if (dropBatchButton) {
    openDropBatchConfirm(
      dropBatchButton.dataset.dropBatchId,
      dropBatchButton.dataset.dropBatchNumber || "this batch",
    );
    return;
  }

  const receiveButton = event.target.closest(".receive-btn");
  if (receiveButton) {
    const productId = document.getElementById("receive_product_id");
    const productName = document.getElementById("receive_product_name");
    const expectedQty = document.getElementById("receive_expected_qty");
    const quantity = document.getElementById("receive_quantity");
    const remarks = document.getElementById("receive_remarks");

    const onOrderQty = receiveButton.dataset.receiveOnorder || "0";

    if (productId) {productId.value = receiveButton.dataset.receiveId;}
    if (productName) {productName.value = receiveButton.dataset.receiveName;}
    if (expectedQty) {expectedQty.value = onOrderQty;}

    // Default the received quantity to what's expected. Editable so a
    // partial or over-shipment can still be recorded accurately.
    if (quantity) {quantity.value = onOrderQty;}
    if (remarks) {remarks.value = "";}

    closeAllMenus();
    openModal(receiveShipmentModal);
    return;
  }

  const placeOrderButton = event.target.closest(".place-order-btn");
  if (placeOrderButton) {
    const productId = document.getElementById("order_product_id");
    const productName = document.getElementById("order_product_name");
    const currentOnOrder = document.getElementById("order_current_on_order");
    const quantity = document.getElementById("order_quantity");
    const remarks = document.getElementById("order_remarks");

    if (productId) {productId.value = placeOrderButton.dataset.orderId;}
    if (productName) {productName.value = placeOrderButton.dataset.orderName;}
    if (currentOnOrder)
      {currentOnOrder.value = placeOrderButton.dataset.orderCurrent || "0";}
    if (quantity) {quantity.value = "";}
    if (remarks) {remarks.value = "";}

    closeAllMenus();
    openModal(placeOrderModal);
    return;
  }

  const toggleButton = event.target.closest("[data-action-menu-toggle]");
  if (toggleButton) {
    event.stopPropagation();

    const wrap = toggleButton.closest(".action-menu-wrap");
    const menuId = wrap ? wrap.dataset.menuFor : null;
    const menu = menuId
      ? document.querySelector('[data-menu-id="' + menuId + '"]')
      : null;

    if (!menu) {return;}

    const isOpen = menu.classList.contains("show");
    closeAllMenus();

    if (!isOpen) {
      positionActionMenu(toggleButton, menu);
      menu.classList.add("show");
    }
    return;
  }

  if (!event.target.closest(".action-menu-wrap")) {
    closeAllMenus();
  }

  if (!event.target.closest(".inventory-filter-wrap")) {
    closeFloatingTools();
  }
});

/* FILTER FORM SUBMIT */
document.addEventListener("submit", (event) => {
  const filterForm = event.target.closest("#inventoryFilterForm");
  if (filterForm) {
    event.preventDefault();
    submitFiltersInstantly();
  }
});

/* STOCK FIELD TOGGLE */
if (stockMovementType) {
  stockMovementType.addEventListener("change", toggleStockOrderFields);
  toggleStockOrderFields();
}

/* Close action menus on scroll/resize so a fixed-position menu
   never ends up detached from the button that opened it. */
window.addEventListener("scroll", () => closeAllMenus(), true);
window.addEventListener("resize", () => closeAllMenus());

initDynamicFilterControls();
escapeOverflowForActionMenus();

/* POPUP AUTO HIDE */
const popupOverlay = document.getElementById("popupOverlay");
if (popupOverlay) {
  setTimeout(() => {
    popupOverlay.remove();
  }, 2600);
}
