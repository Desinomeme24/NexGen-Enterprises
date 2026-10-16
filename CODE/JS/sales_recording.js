document.addEventListener("DOMContentLoaded", function () {
  const saleModal = document.getElementById("saleModal");

  const itemsContainer = document.getElementById("itemsContainer");
  const productCombobox = document.getElementById("productCombobox");
  const productSearchInput = document.getElementById("productSearchInput");
  const productSearchResults = document.getElementById("productSearchResults");
  const selectedProductIdInput = document.getElementById("selectedProductId");
  const clearProductSearchBtn = document.getElementById("clearProductSearch");
  const productEntryQty = document.getElementById("productEntryQty");
  const addProductBtn = document.getElementById("addProductBtn");
  const grandTotalEl = document.getElementById("grandTotal");
  const saleForm = document.getElementById("saleForm");
  const saveSaleBtn = document.getElementById("saveSaleBtn");
  const paymentStatus = document.getElementById("paymentStatus");
  const orderStatus = document.getElementById("orderStatus");
  const amountPaidGroup = document.getElementById("amountPaidGroup");
  const amountPaidInput = document.getElementById("amountPaidInput");
  const checkoutSummaryGrid = document.querySelector(".checkout-summary-grid");

  const salesAccess = window.salesAccess || {};
  const hasAccountsReceivable = salesAccess.accountsReceivable === true;
  const customers = Array.isArray(salesAccess.customers)
    ? salesAccess.customers
    : [];
  const customerInfoCard = document.getElementById("customerInfoCard");
  const customerChoice = document.getElementById("customerChoice");
  const customerIdInput = document.getElementById("customerId");
  const dueDateInput = document.getElementById("dueDateInput");
  const selectedCustomerSummary = document.getElementById(
    "selectedCustomerSummary",
  );
  const newCustomerFields = document.getElementById("newCustomerFields");
  const customerNameInput = document.getElementById("customerNameInput");
  const customerPhoneInput = document.getElementById("customerPhoneInput");
  const customerEmailInput = document.getElementById("customerEmailInput");
  const customerAddressInput = document.getElementById("customerAddressInput");

  const saleConfirmOverlay = document.getElementById("saleConfirmOverlay");
  const saleConfirmCancel = document.getElementById("saleConfirmCancel");
  const saleConfirmYes = document.getElementById("saleConfirmYes");

  const products = Array.isArray(window.products) ? window.products : [];
  let selectedProduct = null;
  let visibleSearchProducts = [];
  let activeSearchIndex = -1;
  let previousPaymentStatus = paymentStatus?.value || "Paid";
  let saleModalReturnFocus = null;
  let saleConfirmReturnFocus = null;
  let saleSubmissionInProgress = false;

  if (saleForm) {
    saleForm.noValidate = true;
  }

  function safeText(value) {
    return String(value ?? "");
  }

  function safeNumber(value, fallback = 0) {
    const num = Number(value);
    return Number.isFinite(num) ? num : fallback;
  }

  function escapeHtml(value) {
    return safeText(value)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  const customersById = new Map(
    customers.map((customer) => [safeText(customer.id), customer]),
  );

  function isCreditSale() {
    return (
      hasAccountsReceivable &&
      ["Unpaid", "Partially Paid"].includes(paymentStatus?.value || "Paid")
    );
  }

  function clearNewCustomerInputs() {
    [
      customerNameInput,
      customerPhoneInput,
      customerEmailInput,
      customerAddressInput,
    ].forEach((input) => {
      if (input) {
        input.value = "";
      }
    });
  }

  function clearCustomerSelection() {
    if (customerChoice) {
      customerChoice.value = "";
    }
    if (customerIdInput) {
      customerIdInput.value = "0";
    }
    if (dueDateInput) {
      dueDateInput.value = "";
    }
    clearNewCustomerInputs();
    if (selectedCustomerSummary) {
      selectedCustomerSummary.hidden = true;
    }
    if (newCustomerFields) {
      newCustomerFields.hidden = true;
    }
  }

  function renderSelectedCustomer(customer) {
    const fields = {
      selectedCustomerName: customer?.customer_name || "—",
      selectedCustomerPhone: customer?.phone || "Not provided",
      selectedCustomerEmail: customer?.email || "Not provided",
      selectedCustomerAddress: customer?.address || "Not provided",
    };

    Object.entries(fields).forEach(([id, value]) => {
      const element = document.getElementById(id);
      if (element) {
        element.textContent = safeText(value);
      }
    });
  }

  function syncCustomerChoice() {
    if (!customerChoice || !customerIdInput) {
      return;
    }

    const selectedValue = customerChoice.value;
    const isNewCustomer = selectedValue === "new";
    const selectedCustomer = customersById.get(selectedValue);

    customerIdInput.value = selectedCustomer ? selectedValue : "0";
    if (newCustomerFields) {
      newCustomerFields.hidden = !isNewCustomer;
    }
    if (selectedCustomerSummary) {
      selectedCustomerSummary.hidden = !selectedCustomer;
    }

    if (selectedCustomer) {
      renderSelectedCustomer(selectedCustomer);
      clearNewCustomerInputs();
    }

    if (!isNewCustomer) {
      clearNewCustomerInputs();
    }

    if (customerNameInput) {
      customerNameInput.required = isNewCustomer;
    }
  }

  function syncCustomerSection(clearWhenDisabled = false) {
    if (!hasAccountsReceivable || !customerInfoCard) {
      return;
    }

    const enabled = isCreditSale();
    customerInfoCard.hidden = !enabled;
    if (dueDateInput) {
      dueDateInput.required = enabled;
    }

    if (!enabled && clearWhenDisabled) {
      clearCustomerSelection();
    } else if (enabled) {
      syncCustomerChoice();
    }
  }

  function validateCustomerDetails() {
    if (!isCreditSale()) {
      return true;
    }

    if (!dueDateInput?.value || !dueDateInput.checkValidity()) {
      showToast("Choose a valid due date for this receivable.", "warning");
      dueDateInput?.focus();
      return false;
    }

    const choice = customerChoice?.value || "";
    if (!choice) {
      showToast(
        "Select an existing customer or choose Create New Customer.",
        "warning",
      );
      customerChoice?.focus();
      return false;
    }

    if (choice === "new") {
      const name = customerNameInput?.value.trim() || "";
      if (name.length < 2) {
        showToast("Enter the customer's full name.", "warning");
        customerNameInput?.focus();
        return false;
      }

      if (
        customerPhoneInput?.value &&
        !/^[0-9+().\- ]{7,20}$/.test(customerPhoneInput.value)
      ) {
        showToast("Enter a valid customer phone number.", "warning");
        customerPhoneInput.focus();
        return false;
      }

      if (customerEmailInput?.value && !customerEmailInput.checkValidity()) {
        showToast("Enter a valid customer email address.", "warning");
        customerEmailInput.focus();
        return false;
      }
    } else if (!customersById.has(choice)) {
      showToast("The selected customer is no longer available.", "warning");
      customerChoice?.focus();
      return false;
    }

    return true;
  }

  function getToastRoot() {
    let root = document.getElementById("nxToastRoot");
    if (!root) {
      root = document.createElement("div");
      root.id = "nxToastRoot";
      root.className = "nx-toast-root";
      document.body.appendChild(root);
    }
    return root;
  }

  function showToast(message, type = "info", duration = 3200) {
    const root = getToastRoot();
    const toast = document.createElement("div");
    toast.className = `nx-toast ${type}`;

    const titleMap = {
      success: "Success",
      error: "Attention",
      warning: "Notice",
      info: "Message",
    };

    const accent = document.createElement("div");
    accent.className = "nx-toast-accent";

    const content = document.createElement("div");
    content.className = "nx-toast-content";

    const title = document.createElement("div");
    title.className = "nx-toast-title";
    title.textContent = titleMap[type] || "Message";

    const msg = document.createElement("div");
    msg.className = "nx-toast-message";
    msg.textContent = safeText(message);

    const closeBtn = document.createElement("button");
    closeBtn.type = "button";
    closeBtn.className = "nx-toast-close";
    closeBtn.setAttribute("aria-label", "Close notification");
    closeBtn.textContent = "×";

    content.appendChild(title);
    content.appendChild(msg);

    toast.appendChild(accent);
    toast.appendChild(content);
    toast.appendChild(closeBtn);

    root.appendChild(toast);

    requestAnimationFrame(() => {
      toast.classList.add("show");
    });

    const removeToast = () => {
      toast.classList.remove("show");
      setTimeout(() => {
        if (toast.parentNode) {
          toast.parentNode.removeChild(toast);
        }
      }, 220);
    };

    closeBtn.addEventListener("click", removeToast);
    setTimeout(removeToast, duration);
  }

  function openSaleModal() {
    if (!saleModal) {
      return;
    }
    saleModalReturnFocus = document.activeElement;
    saleModal.classList.add("show");
    saleModal.setAttribute("aria-hidden", "false");
    document.body.classList.add("sale-app-open");
    document.body.style.overflow = "hidden";
    if (typeof goToWizardStep === "function") {
      goToWizardStep(1);
    }
    requestAnimationFrame(() => {
      saleModal.querySelector(".modal-close")?.focus({ preventScroll: true });
    });
  }

  function closeSaleModal() {
    if (!saleModal) {
      return;
    }
    saleModal.classList.remove("show");
    saleModal.setAttribute("aria-hidden", "true");
    document.body.classList.remove("sale-app-open");
    closeSaleConfirm();
    document.body.style.overflow = "";
    if (saleModalReturnFocus instanceof HTMLElement) {
      saleModalReturnFocus.focus({ preventScroll: true });
    }
    saleModalReturnFocus = null;
  }

  function openSaleConfirm() {
    if (!saleConfirmOverlay || !saleConfirmYes) {
      showToast(
        "Custom confirmation modal is not available. Please try again.",
        "error",
      );
      return;
    }

    if (saleConfirmOverlay.classList.contains("show")) {
      return;
    }

    saleConfirmReturnFocus = document.activeElement;
    saleConfirmOverlay.classList.add("show");
    saleConfirmOverlay.setAttribute("aria-hidden", "false");
    document.body.style.overflow = "hidden";
    requestAnimationFrame(() => {
      saleConfirmYes.focus({ preventScroll: true });
    });
  }

  function closeSaleConfirm({ restoreFocus = true } = {}) {
    if (!saleConfirmOverlay) {
      return;
    }
    saleConfirmOverlay.classList.remove("show");
    saleConfirmOverlay.setAttribute("aria-hidden", "true");

    if (saleModal && saleModal.classList.contains("show")) {
      document.body.style.overflow = "hidden";
    } else {
      document.body.style.overflow = "auto";
    }

    if (restoreFocus && saleConfirmReturnFocus instanceof HTMLElement) {
      saleConfirmReturnFocus.focus({ preventScroll: true });
    }
    saleConfirmReturnFocus = null;
  }

  function syncOrderStatusWithPayment() {
    if (!paymentStatus || !orderStatus) {
      return;
    }
    const status = paymentStatus.value;
    orderStatus.value = status === "Paid" ? "Fulfilled" : "Pending";
  }

  if (saleConfirmCancel) {
    saleConfirmCancel.addEventListener("click", function () {
      closeSaleConfirm();
    });
  }

  if (saleConfirmOverlay) {
    saleConfirmOverlay.addEventListener("click", function (e) {
      if (e.target === saleConfirmOverlay) {
        closeSaleConfirm();
      }
    });
  }

  if (saleModal) {
    saleModal.addEventListener("click", function (e) {
      if (e.target === saleModal) {
        closeSaleModal();
      }
    });
  }

  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") {
      const quickViewModal = document.getElementById("quickViewModal");

      if (saleConfirmOverlay && saleConfirmOverlay.classList.contains("show")) {
        closeSaleConfirm();
        return;
      }

      if (quickViewModal && quickViewModal.classList.contains("show")) {
        closeQuickView();
        return;
      }

      if (saleModal && saleModal.classList.contains("show")) {
        closeSaleModal();
      }
    }
  });

  function formatMoney(value) {
    return Number(value).toFixed(2);
  }

  const searchableProducts = products.map((product) => {
    const discount = Math.min(
      Math.max(safeNumber(product.discount_percent), 0),
      100,
    );
    const basePrice = safeNumber(product.selling_price);

    return {
      id: safeText(product.id),
      code: safeText(product.product_code),
      name: safeText(product.product_name),
      imageUrl:
        safeText(product.image_url) || "../../IMAGES/default-product.svg",
      stock: safeNumber(product.stock_quantity),
      basePrice,
      discount,
      netPrice: Number((basePrice * (1 - discount / 100)).toFixed(2)),
    };
  });

  const productsById = new Map(
    searchableProducts.map((product) => [product.id, product]),
  );

  function updateSearchResultHighlight() {
    if (!productSearchResults) {
      return;
    }
    productSearchResults
      .querySelectorAll(".product-search-option")
      .forEach((option, index) => {
        const active = index === activeSearchIndex;
        option.classList.toggle("is-active", active);
        option.setAttribute("aria-selected", active ? "true" : "false");
        if (active) {
          option.scrollIntoView({ block: "nearest" });
        }
      });
  }

  function closeProductSearchResults() {
    if (!productSearchResults || !productSearchInput) {
      return;
    }
    productSearchResults.classList.remove("show");
    productSearchInput.setAttribute("aria-expanded", "false");
    activeSearchIndex = -1;
  }

  function renderProductSearchResults(query = "") {
    if (!productSearchResults || !productSearchInput) {
      return;
    }

    const normalizedQuery = safeText(query).trim().toLowerCase();
    visibleSearchProducts = searchableProducts
      .filter((product) => {
        if (!normalizedQuery) {
          return true;
        }
        return (
          product.name.toLowerCase().includes(normalizedQuery) ||
          product.code.toLowerCase().includes(normalizedQuery)
        );
      })
      .slice(0, 15);
    activeSearchIndex = -1;

    if (!visibleSearchProducts.length) {
      productSearchResults.innerHTML = `
        <div class="product-search-empty">
          <i class="bi bi-search"></i>
          <span>No matching product found.</span>
        </div>`;
    } else {
      productSearchResults.innerHTML = visibleSearchProducts
        .map((product) => {
          const unavailable = product.stock <= 0;
          const discountPill =
            product.discount > 0
              ? `<span class="product-result-discount">-${escapeHtml(product.discount)}%</span>`
              : "";
          return `
            <button
              type="button"
              class="product-search-option"
              data-product-id="${escapeHtml(product.id)}"
              role="option"
              aria-selected="false"
              ${unavailable ? "disabled" : ""}>
              <img src="${escapeHtml(product.imageUrl)}" alt="">
              <span class="product-result-copy">
                <strong>${escapeHtml(product.name)}</strong>
                <small>${escapeHtml(product.code || "No product code")} &bull; Stock: ${escapeHtml(product.stock)}</small>
              </span>
              <span class="product-result-price">
                ${discountPill}
                <strong>₱${formatMoney(product.netPrice)}</strong>
              </span>
            </button>`;
        })
        .join("");
    }

    productSearchResults.querySelectorAll("img").forEach((image) => {
      image.addEventListener(
        "error",
        function () {
          image.src = "../../IMAGES/default-product.svg";
        },
        { once: true },
      );
    });

    productSearchResults.classList.add("show");
    productSearchInput.setAttribute("aria-expanded", "true");
  }

  function clearProductSelection({ keepFocus = false } = {}) {
    selectedProduct = null;
    if (selectedProductIdInput) {
      selectedProductIdInput.value = "";
    }
    if (productSearchInput) {
      productSearchInput.value = "";
    }
    if (productEntryQty) {
      productEntryQty.value = "1";
      productEntryQty.removeAttribute("max");
    }
    if (clearProductSearchBtn) {
      clearProductSearchBtn.classList.remove("show");
    }
    closeProductSearchResults();
    if (keepFocus && productSearchInput) {
      productSearchInput.focus();
    }
  }

  function selectSearchProduct(product) {
    if (!product || product.stock <= 0) {
      return;
    }
    selectedProduct = product;
    if (selectedProductIdInput) {
      selectedProductIdInput.value = product.id;
    }
    if (productSearchInput) {
      productSearchInput.value = product.name;
    }
    if (productEntryQty) {
      productEntryQty.value = "1";
      productEntryQty.max = String(product.stock);
    }
    if (clearProductSearchBtn) {
      clearProductSearchBtn.classList.add("show");
    }
    closeProductSearchResults();
    productEntryQty?.focus();
    productEntryQty?.select();
  }

  function resolveTypedProduct() {
    if (selectedProduct) {
      return selectedProduct;
    }
    const query = safeText(productSearchInput?.value).trim().toLowerCase();
    if (!query) {
      return null;
    }
    return (
      searchableProducts.find(
        (product) =>
          product.name.toLowerCase() === query ||
          product.code.toLowerCase() === query,
      ) || null
    );
  }

  function updateItemsEmptyState() {
    if (!itemsContainer) {
      return;
    }
    const hasRows = Boolean(itemsContainer.querySelector(".item-row"));
    const existingEmpty = itemsContainer.querySelector(".items-empty-state");

    if (hasRows && existingEmpty) {
      existingEmpty.remove();
    } else if (!hasRows && !existingEmpty) {
      itemsContainer.innerHTML = `
        <div class="items-empty-state" id="itemsEmptyState">
          <i class="bi bi-basket"></i>
          <span>Search for a product above to begin the sale.</span>
        </div>`;
    }
  }

  function createItemRow(product, quantity) {
    if (!itemsContainer || !product) {
      return null;
    }
    updateItemsEmptyState();
    itemsContainer.querySelector(".items-empty-state")?.remove();

    const row = document.createElement("div");
    row.className = "item-row";
    row.dataset.productId = product.id;
    row.dataset.productName = product.name;
    row.dataset.productCode = product.code;
    row.dataset.productImage = product.imageUrl;
    row.dataset.basePrice = String(product.basePrice);
    row.dataset.discount = String(product.discount);
    row.dataset.stock = String(product.stock);

    const discountNote =
      product.discount > 0
        ? `<small class="item-discount-note">-${escapeHtml(product.discount)}% product discount applied</small>`
        : `<small class="item-stock-note">Stock: ${escapeHtml(product.stock)}</small>`;

    row.innerHTML = `
      <div class="item-product-cell" data-label="Product">
        <img class="item-product-image" src="${escapeHtml(product.imageUrl)}" alt="">
        <div class="item-product-copy">
          <strong>${escapeHtml(product.name)}</strong>
          <span>${escapeHtml(product.code || "No product code")}</span>
          ${discountNote}
        </div>
        <input type="hidden" name="product_id[]" class="product-id-input" value="${escapeHtml(product.id)}">
      </div>
      <div class="item-qty-cell" data-label="Qty">
        <input type="number" name="quantity[]" class="qty-input" min="0.001" max="${escapeHtml(product.stock)}" step="any" inputmode="decimal" value="${escapeHtml(quantity)}">
      </div>
      <div class="item-price-cell" data-label="Unit Price">
        <input type="text" name="unit_price[]" class="price-input readonly-box" value="${formatMoney(product.basePrice)}" readonly>
      </div>
      <div class="item-subtotal-cell" data-label="Subtotal">
        <input type="text" class="subtotal-input readonly-box" value="0.00" readonly>
      </div>
      <div class="item-remove-cell" data-label="Remove">
        <button type="button" class="row-remove" aria-label="Remove ${escapeHtml(product.name)}"><i class="bi bi-trash3"></i></button>
      </div>
      <input type="hidden" name="discount_percent[]" class="discount-percent-input" value="${escapeHtml(product.discount)}">
      <input type="hidden" name="line_subtotal[]" class="line-subtotal-input" value="0.00">
    `;

    const image = row.querySelector(".item-product-image");
    image?.addEventListener(
      "error",
      function () {
        image.src = "../../IMAGES/default-product.svg";
      },
      { once: true },
    );

    itemsContainer.appendChild(row);
    calculateRow(row);
    return row;
  }

  function addSelectedProductToList() {
    const product = resolveTypedProduct();
    if (!product) {
      showToast("Search for and select a product first.", "warning");
      productSearchInput?.focus();
      renderProductSearchResults(productSearchInput?.value || "");
      return;
    }

    if (product.stock <= 0) {
      showToast(`${product.name} is out of stock.`, "error");
      return;
    }

    const quantity = safeNumber(productEntryQty?.value);
    if (quantity <= 0) {
      showToast("Quantity must be greater than zero.", "warning");
      productEntryQty?.focus();
      return;
    }

    const existingRow = Array.from(
      itemsContainer?.querySelectorAll(".item-row") || [],
    ).find((row) => row.dataset.productId === product.id);
    const existingQty = safeNumber(
      existingRow?.querySelector(".qty-input")?.value,
    );
    const requestedQty = existingQty + quantity;

    if (requestedQty > product.stock) {
      showToast(
        `Only ${product.stock} unit(s) of ${product.name} are available.`,
        "error",
      );
      return;
    }

    if (existingRow) {
      const rowQtyInput = existingRow.querySelector(".qty-input");
      if (rowQtyInput) {
        rowQtyInput.value = String(requestedQty);
      }
      calculateRow(existingRow);
    } else {
      createItemRow(product, quantity);
    }

    clearProductSelection({ keepFocus: window.innerWidth > 700 });
  }

  function calculateRow(rowOrElement) {
    const row = rowOrElement.closest
      ? rowOrElement.closest(".item-row")
      : rowOrElement;
    if (!row) {
      return;
    }

    const qty = parseFloat(row.querySelector(".qty-input").value) || 0;
    const price = parseFloat(row.querySelector(".price-input").value) || 0;
    const stock = safeNumber(row.dataset.stock);
    const discount = Math.min(
      Math.max(safeNumber(row.dataset.discount), 0),
      100,
    );
    const subtotal = qty * price * (1 - discount / 100);

    row.classList.toggle("has-stock-error", qty > stock || qty <= 0);
    row
      .querySelector(".qty-input")
      ?.setAttribute(
        "aria-invalid",
        qty > stock || qty <= 0 ? "true" : "false",
      );

    row.querySelector(".subtotal-input").value = formatMoney(subtotal);
    const discountInput = row.querySelector(".discount-percent-input");
    const lineSubtotalInput = row.querySelector(".line-subtotal-input");
    if (discountInput) {
      discountInput.value = String(discount);
    }
    if (lineSubtotalInput) {
      lineSubtotalInput.value = formatMoney(subtotal);
    }

    const discountNote = row.querySelector(".item-discount-note");
    if (discountNote) {
      discountNote.textContent =
        discount > 0 ? `-${discount}% discount applied` : "";
    }

    calculateGrandTotal();
  }

  function calculateGrandTotal() {
    const totals = getSaleTotals();
    const total = totals.total;

    if (grandTotalEl) {
      grandTotalEl.textContent = formatMoney(total);
    }

    togglePaymentFields(total);
    updateChangeDue();
    renderLiveReceipt();

    if (typeof wizardCurrentStep !== "undefined" && wizardCurrentStep === 3) {
      renderWizardReview();
    }
  }

  function getSaleTotals() {
    let subtotal = 0;
    let total = 0;

    document.querySelectorAll(".item-row").forEach((row) => {
      const qty = safeNumber(row.querySelector(".qty-input")?.value);
      const basePrice = safeNumber(row.dataset.basePrice);
      const lineTotal = safeNumber(row.querySelector(".subtotal-input")?.value);
      subtotal += qty * basePrice;
      total += lineTotal;
    });

    return {
      subtotal,
      discount: Math.max(subtotal - total, 0),
      total,
    };
  }

  function updateChangeDue() {
    const total = getGrandTotalValue();
    const amountPaid = safeNumber(amountPaidInput?.value);
    const status = paymentStatus?.value || "Paid";
    const isPaid = status === "Paid";
    const settlement = isPaid
      ? Math.max(amountPaid - total, 0)
      : Math.max(total - amountPaid, 0);
    const changeDueEl = document.getElementById("changeDue");
    const settlementLabel = document.getElementById("settlementLabel");
    const reviewSettlementLabel = document.getElementById(
      "reviewSettlementLabel",
    );
    const settlementCard = document.getElementById("settlementCard");

    if (changeDueEl) {
      changeDueEl.textContent = formatMoney(settlement);
    }
    if (settlementLabel) {
      settlementLabel.textContent = isPaid ? "Change Due" : "Balance Due";
    }
    if (reviewSettlementLabel) {
      reviewSettlementLabel.textContent = isPaid ? "Change Due" : "Balance Due";
    }
    settlementCard?.classList.toggle("is-balance", !isPaid);
    return settlement;
  }

  function renderLiveReceipt() {
    const receiptLines = document.getElementById("receiptLines");
    const receiptTotalEl = document.getElementById("receiptTotal");
    const receiptSubtotalEl = document.getElementById("receiptSubtotal");
    const receiptDiscountEl = document.getElementById("receiptDiscount");
    const receiptItemCountEl = document.getElementById("receiptItemCount");
    const receiptRowCountEl = document.getElementById("receiptRowCount");
    if (!receiptLines) {
      return;
    }

    const rows = document.querySelectorAll(".item-row");
    let html = "";
    let itemCount = 0;

    rows.forEach((row) => {
      const qtyInput = row.querySelector(".qty-input");
      const subtotalInput = row.querySelector(".subtotal-input");
      const name = row.dataset.productName || "Item";
      const discount = safeNumber(row.dataset.discount);
      const qty = qtyInput ? qtyInput.value : "1";
      itemCount += safeNumber(qty);
      const subtotal = subtotalInput ? parseFloat(subtotalInput.value) || 0 : 0;
      const discountBadge =
        discount > 0
          ? `<small class="receipt-line-discount">-${escapeHtml(discount)}%</small>`
          : "";

      html += `<div class="receipt-line"><span>${escapeHtml(name)} &times; ${escapeHtml(qty)} ${discountBadge}</span><span>₱${formatMoney(subtotal)}</span></div>`;
    });

    receiptLines.innerHTML =
      html ||
      '<p class="receipt-empty">No items yet — add a product to see it here.</p>';
    const totals = getSaleTotals();
    if (receiptItemCountEl) {
      receiptItemCountEl.textContent = safeText(itemCount);
    }
    if (receiptRowCountEl) {
      receiptRowCountEl.textContent = safeText(rows.length);
    }
    if (receiptSubtotalEl) {
      receiptSubtotalEl.textContent = formatMoney(totals.subtotal);
    }
    if (receiptDiscountEl) {
      receiptDiscountEl.textContent = formatMoney(totals.discount);
    }
    if (receiptTotalEl) {
      receiptTotalEl.textContent = formatMoney(totals.total);
    }
  }

  function removeRow(button) {
    const row = button.closest(".item-row");
    if (row) {
      row.remove();
      updateItemsEmptyState();
      calculateGrandTotal();
    }
  }

  function resetSaleForm() {
    if (!saleForm) {
      return;
    }

    saleForm.reset();

    if (itemsContainer) {
      itemsContainer.innerHTML = "";
      updateItemsEmptyState();
    }

    clearProductSelection();

    if (grandTotalEl) {
      grandTotalEl.textContent = "0.00";
    }

    const receiptImageInput = document.getElementById("receiptImageInput");
    const attachReceiptText = document.getElementById("attachReceiptText");
    if (receiptImageInput) {
      receiptImageInput.value = "";
    }
    if (attachReceiptText) {
      attachReceiptText.textContent = "Attach Receipt Photo (optional)";
    }

    syncOrderStatusWithPayment();
    togglePaymentFields();
    syncCustomerSection(true);
    updateChangeDue();
    renderLiveReceipt();

    if (typeof goToWizardStep === "function") {
      goToWizardStep(1);
    }
  }

  function getGrandTotalValue() {
    return (
      parseFloat((grandTotalEl?.textContent || "0").replace(/,/g, "")) || 0
    );
  }

  function togglePaymentFields(totalOverride) {
    if (!paymentStatus) {
      return;
    }

    const total =
      typeof totalOverride === "number" ? totalOverride : getGrandTotalValue();
    const status = paymentStatus.value || "Paid";
    const statusChanged = status !== previousPaymentStatus;

    if (checkoutSummaryGrid) {
      checkoutSummaryGrid.dataset.paymentStatus = status;
    }

    if (amountPaidGroup) {
      const shouldShow = status !== "Unpaid";
      amountPaidGroup.hidden = !shouldShow;
      amountPaidGroup.style.display = shouldShow ? "flex" : "none";
    }

    if (amountPaidInput) {
      const currentAmount = safeNumber(amountPaidInput.value);
      amountPaidInput.readOnly = status === "Unpaid";
      amountPaidInput.removeAttribute("max");

      if (status === "Paid") {
        amountPaidInput.min = "0";
        if (statusChanged || currentAmount < total || currentAmount <= 0) {
          amountPaidInput.value = total > 0 ? formatMoney(total) : "0.00";
        }
      } else if (status === "Partially Paid") {
        amountPaidInput.min = "0.01";
        if (total > 0.01) {
          amountPaidInput.max = formatMoney(total - 0.01);
        }
        if (statusChanged || currentAmount >= total) {
          amountPaidInput.value = "0.00";
        }
      } else {
        amountPaidInput.min = "0";
        amountPaidInput.value = "0.00";
      }
    }

    syncOrderStatusWithPayment();
    syncCustomerSection(statusChanged && status === "Paid");
    previousPaymentStatus = status;
    updateChangeDue();
  }

  function validateSaleForm() {
    if (!saleForm) {
      return false;
    }

    const payment = paymentStatus ? paymentStatus.value : "Paid";
    const total = getGrandTotalValue();
    const amountPaid = parseFloat(amountPaidInput?.value || "0") || 0;

    if (!validateCustomerDetails()) {
      goToWizardStep(1);
      return false;
    }

    if (total <= 0) {
      showToast(
        "Please add at least one product with a valid amount.",
        "warning",
      );
      return false;
    }

    const rows = Array.from(document.querySelectorAll(".item-row"));
    if (!rows.length) {
      showToast("Please add at least one product item.", "warning");
      return false;
    }

    for (let i = 0; i < rows.length; i++) {
      const row = rows[i];
      const productIdInput = row.querySelector(".product-id-input");
      const qtyInput = row.querySelector(".qty-input");
      const priceInput = row.querySelector(".price-input");

      if (!productIdInput?.value || !productsById.has(productIdInput.value)) {
        showToast(`Please select a product for item ${i + 1}.`, "warning");
        return false;
      }

      const qty = parseFloat(qtyInput?.value || "0") || 0;
      const price = parseFloat(priceInput?.value || "0") || 0;
      const stock = safeNumber(row.dataset.stock);
      const productName = row.dataset.productName || `item ${i + 1}`;

      if (qty <= 0) {
        showToast(
          `Quantity must be greater than zero for item ${i + 1}.`,
          "warning",
        );
        return false;
      }

      if (price < 0) {
        showToast(
          `Unit price cannot be negative for item ${i + 1}.`,
          "warning",
        );
        return false;
      }

      if (qty > stock) {
        showToast(`Insufficient stock for ${productName}.`, "error");
        return false;
      }
    }

    if (payment === "Paid") {
      if (amountPaid + 0.009 < total) {
        showToast(
          "For paid sales, the amount paid must cover the grand total.",
          "warning",
        );
        return false;
      }
    } else if (payment === "Partially Paid") {
      if (amountPaid <= 0 || amountPaid >= total) {
        showToast(
          "For a partially paid sale, enter an amount greater than zero and lower than the total.",
          "warning",
        );
        amountPaidInput?.focus();
        goToWizardStep(2);
        return false;
      }
    } else if (payment === "Unpaid" && amountPaid !== 0) {
      if (amountPaidInput) {
        amountPaidInput.value = "0.00";
      }
    }

    syncOrderStatusWithPayment();
    return true;
  }

  async function doSubmitSaleAjax() {
    if (!saleForm || saleSubmissionInProgress) {
      return;
    }

    saleSubmissionInProgress = true;
    let saleSaved = false;

    syncOrderStatusWithPayment();

    const formData = new FormData(saleForm);

    if (saveSaleBtn) {
      saveSaleBtn.disabled = true;
      saveSaleBtn.setAttribute("aria-busy", "true");
      saveSaleBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving...';
    }
    if (saleConfirmYes) {
      saleConfirmYes.disabled = true;
      saleConfirmYes.setAttribute("aria-busy", "true");
      saleConfirmYes.innerHTML =
        '<i class="bi bi-hourglass-split"></i> Saving...';
    }

    try {
      const response = await fetch("process_sale_ajax.php", {
        method: "POST",
        body: formData,
      });

      const rawText = await response.text();
      let data;

      try {
        data = JSON.parse(rawText);
      } catch (jsonError) {
        console.error("Invalid JSON response:", rawText);
        throw new Error("Server returned an invalid response.");
      }

      if (data.success) {
        saleSaved = true;
        showToast(data.message || "Sale saved successfully.", "success", 4200);

        if (saveSaleBtn) {
          saveSaleBtn.innerHTML =
            '<i class="bi bi-check-circle-fill"></i> Saved Successfully';
        }

        setTimeout(() => {
          closeSaleModal();
          resetSaleForm();
        }, 1100);

        setTimeout(() => {
          const saleUrl = new URL("sale_view.php", window.location.href);

          saleUrl.searchParams.set("id", data.sale_id);

          // Preserve the current Sales Recording filter state.
          const currentParams = new URLSearchParams(window.location.search);

          [
            "filter",
            "search",
            "period",
            "ref_date",
            "date_from",
            "date_to",
            "cashier_id",
          ].forEach((key) => {
            if (currentParams.has(key)) {
              saleUrl.searchParams.set(key, currentParams.get(key));
            }
          });

          window.location.href = saleUrl.pathname + saleUrl.search;
        }, 2200);
      } else {
        showToast(data.message || "Failed to save sale.", "error", 4200);
      }
    } catch (error) {
      console.error("AJAX error:", error);
      showToast(
        error.message || "An error occurred while saving the sale.",
        "error",
        4200,
      );
    } finally {
      saleSubmissionInProgress = false;

      if (!saleSaved && saveSaleBtn) {
        saveSaleBtn.disabled = false;
        saveSaleBtn.removeAttribute("aria-busy");
        saveSaleBtn.innerHTML = '<i class="bi bi-check-circle"></i> Save Sale';
      }

      if (saleConfirmYes) {
        saleConfirmYes.disabled = false;
        saleConfirmYes.removeAttribute("aria-busy");
        saleConfirmYes.innerHTML =
          '<i class="bi bi-check-circle"></i> Save Sale';
      }
    }
  }

  async function submitSaleAjax(e) {
    e.preventDefault();

    if (!saleForm || saleSubmissionInProgress) {
      return;
    }
    if (!validateSaleForm()) {
      return;
    }

    openSaleConfirm();
  }

  if (saleConfirmYes) {
    saleConfirmYes.addEventListener("click", async function () {
      if (saleSubmissionInProgress) {
        return;
      }
      closeSaleConfirm({ restoreFocus: false });
      await doSubmitSaleAjax();
    });
  }

  if (itemsContainer) {
    itemsContainer.addEventListener("input", function (e) {
      if (e.target.classList.contains("qty-input")) {
        calculateRow(e.target);
      }
    });

    itemsContainer.addEventListener("click", function (e) {
      const removeButton = e.target.closest(".row-remove");
      if (removeButton) {
        removeRow(removeButton);
      }
    });
  }

  if (productSearchInput) {
    productSearchInput.addEventListener("focus", function () {
      renderProductSearchResults(productSearchInput.value);
    });

    productSearchInput.addEventListener("input", function () {
      if (
        selectedProduct &&
        productSearchInput.value.trim() !== selectedProduct.name
      ) {
        selectedProduct = null;
        if (selectedProductIdInput) {
          selectedProductIdInput.value = "";
        }
      }
      clearProductSearchBtn?.classList.toggle(
        "show",
        productSearchInput.value.length > 0,
      );
      renderProductSearchResults(productSearchInput.value);
    });

    productSearchInput.addEventListener("keydown", function (e) {
      if (e.key === "ArrowDown" || e.key === "ArrowUp") {
        e.preventDefault();
        if (!productSearchResults?.classList.contains("show")) {
          renderProductSearchResults(productSearchInput.value);
        }
        if (!visibleSearchProducts.length) {
          return;
        }
        const direction = e.key === "ArrowDown" ? 1 : -1;
        activeSearchIndex =
          (activeSearchIndex + direction + visibleSearchProducts.length) %
          visibleSearchProducts.length;
        updateSearchResultHighlight();
        return;
      }

      if (e.key === "Enter") {
        e.preventDefault();
        if (
          activeSearchIndex >= 0 &&
          visibleSearchProducts[activeSearchIndex]
        ) {
          selectSearchProduct(visibleSearchProducts[activeSearchIndex]);
          return;
        }

        const exactProduct = resolveTypedProduct();
        if (exactProduct) {
          selectSearchProduct(exactProduct);
        } else {
          renderProductSearchResults(productSearchInput.value);
        }
      }

      if (e.key === "Escape") {
        closeProductSearchResults();
      }
    });
  }

  productSearchResults?.addEventListener("click", function (e) {
    const option = e.target.closest(".product-search-option");
    if (!option || option.disabled) {
      return;
    }
    selectSearchProduct(productsById.get(option.dataset.productId));
  });

  clearProductSearchBtn?.addEventListener("click", function () {
    clearProductSelection({ keepFocus: true });
    renderProductSearchResults();
  });

  addProductBtn?.addEventListener("click", addSelectedProductToList);

  productEntryQty?.addEventListener("keydown", function (e) {
    if (e.key === "Enter") {
      e.preventDefault();
      addSelectedProductToList();
    }
  });

  document.addEventListener("click", function (e) {
    if (!productCombobox?.contains(e.target)) {
      closeProductSearchResults();
    }
  });

  if (saleForm) {
    saleForm.addEventListener("submit", submitSaleAjax);
  }

  saveSaleBtn?.addEventListener("click", function (event) {
    event.preventDefault();
    if (!saleForm || saleSubmissionInProgress) {
      return;
    }

    if (typeof saleForm.requestSubmit === "function") {
      saleForm.requestSubmit();
    } else {
      saleForm.dispatchEvent(
        new Event("submit", { bubbles: true, cancelable: true }),
      );
    }
  });

  customerChoice?.addEventListener("change", function () {
    syncCustomerChoice();
    if (customerChoice.value === "new") {
      customerNameInput?.focus();
    }
  });

  if (paymentStatus) {
    paymentStatus.addEventListener("change", function () {
      togglePaymentFields();
      syncOrderStatusWithPayment();
      updateChangeDue();
    });
  }

  if (amountPaidInput) {
    amountPaidInput.addEventListener("input", function () {
      const status = paymentStatus ? paymentStatus.value : "Paid";
      if (orderStatus) {
        orderStatus.value = status === "Paid" ? "Fulfilled" : "Pending";
      }
      updateChangeDue();
      if (typeof wizardCurrentStep !== "undefined" && wizardCurrentStep === 3) {
        renderWizardReview();
      }
    });
  }

  /* ================= TOOLBAR ICON FILTER DROPDOWNS ================= */
  const dropdownToggleBtns = document.querySelectorAll(
    "[data-dropdown-toggle]",
  );

  // Move each dropdown panel to <body>. .page-shell has a backdrop-filter,
  // which (like transform/filter/will-change) creates its own containing
  // block for any position:fixed descendants — so left/top set from
  // getBoundingClientRect() end up relative to .page-shell instead of the
  // viewport, landing the panel far from its trigger button. Re-parenting
  // to <body> keeps position:fixed anchored to the viewport as intended.
  dropdownToggleBtns.forEach((btn) => {
    const targetId = btn.getAttribute("data-dropdown-toggle");
    const dropdown = document.getElementById(targetId);
    if (dropdown && dropdown.parentElement !== document.body) {
      document.body.appendChild(dropdown);
    }
  });

  function closeAllIconDropdowns() {
    document.querySelectorAll(".icon-filter-dropdown.show").forEach((dd) => {
      dd.classList.remove("show");
    });
    document.querySelectorAll(".icon-filter-btn.open").forEach((btn) => {
      btn.classList.remove("open");
      btn.setAttribute("aria-expanded", "false");
    });
  }

  dropdownToggleBtns.forEach((btn) => {
    btn.addEventListener("click", function (e) {
      e.stopPropagation();
      const targetId = btn.getAttribute("data-dropdown-toggle");
      const dropdown = document.getElementById(targetId);
      if (!dropdown) {
        return;
      }

      const willOpen = !dropdown.classList.contains("show");
      closeAllIconDropdowns();

      if (willOpen) {
        dropdown.classList.add("show");
        btn.classList.add("open");
        btn.setAttribute("aria-expanded", "true");

        const margin = 12;
        const btnRect = btn.getBoundingClientRect();
        const ddRect = dropdown.getBoundingClientRect();

        // Prefer opening to the right of the button.
        let left = btnRect.right + 10;
        if (left + ddRect.width > window.innerWidth - margin) {
          // Not enough room on the right — fall back to the left of the button.
          left = btnRect.left - ddRect.width - 10;
        }
        if (left < margin) {
          left = margin;
        }
        const maxLeft = window.innerWidth - ddRect.width - margin;
        if (left > maxLeft) {
          left = maxLeft;
        }

        // Align vertically with the button, nudged up if it would overflow the bottom.
        let top = btnRect.top;
        const maxTop = window.innerHeight - ddRect.height - margin;
        if (top > maxTop) {
          top = maxTop;
        }
        if (top < margin) {
          top = margin;
        }

        dropdown.style.left = left + "px";
        dropdown.style.top = top + "px";
      }
    });
  });

  window.addEventListener("resize", closeAllIconDropdowns);
  window.addEventListener("scroll", closeAllIconDropdowns, true);

  document.addEventListener("click", function (e) {
    if (
      !e.target.closest(".icon-filter-wrap") &&
      !e.target.closest(".icon-filter-dropdown")
    ) {
      closeAllIconDropdowns();
    }
  });

  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") {
      closeAllIconDropdowns();
    }
  });

  /* ================= SALES LEDGER PAGINATION (click-based) ================= */
  const salesGrid = document.getElementById("salesGrid");
  const salesPagination = document.getElementById("salesPagination");
  const SALES_PAGE_SIZE = 8;
  let salesCurrentPage = 1;

  function getSaleCards() {
    return salesGrid
      ? Array.from(salesGrid.querySelectorAll(".sale-card"))
      : [];
  }

  function renderSalesPage() {
    const cards = getSaleCards();
    if (!salesPagination) {
      return;
    }

    if (!cards.length) {
      salesPagination.innerHTML = "";
      return;
    }

    const totalPages = Math.max(1, Math.ceil(cards.length / SALES_PAGE_SIZE));
    if (salesCurrentPage > totalPages) {
      salesCurrentPage = totalPages;
    }
    if (salesCurrentPage < 1) {
      salesCurrentPage = 1;
    }

    cards.forEach((card, index) => {
      const page = Math.floor(index / SALES_PAGE_SIZE) + 1;
      card.style.display = page === salesCurrentPage ? "" : "none";
    });

    if (totalPages <= 1) {
      salesPagination.innerHTML = "";
      return;
    }

    let html = `<button type="button" class="page-nav-btn" data-page-nav="prev" ${salesCurrentPage === 1 ? "disabled" : ""} aria-label="Previous page"><i class="bi bi-chevron-left"></i></button>`;

    for (let p = 1; p <= totalPages; p++) {
      html += `<button type="button" class="page-num-btn ${p === salesCurrentPage ? "active" : ""}" data-page-num="${p}">${p}</button>`;
    }

    html += `<button type="button" class="page-nav-btn" data-page-nav="next" ${salesCurrentPage === totalPages ? "disabled" : ""} aria-label="Next page"><i class="bi bi-chevron-right"></i></button>`;

    salesPagination.innerHTML = html;
  }

  if (salesPagination) {
    salesPagination.addEventListener("click", function (e) {
      const navBtn = e.target.closest("[data-page-nav]");
      const numBtn = e.target.closest("[data-page-num]");

      if (navBtn) {
        salesCurrentPage +=
          navBtn.getAttribute("data-page-nav") === "next" ? 1 : -1;
        renderSalesPage();
      } else if (numBtn) {
        salesCurrentPage =
          parseInt(numBtn.getAttribute("data-page-num"), 10) || 1;
        renderSalesPage();
      }
    });
  }

  renderSalesPage();

  /* ================= SALES SEARCH (AJAX, submit-only) ================= */
  const salesSearchForm = document.querySelector(".sales-search-form");
  const salesSearchInput = document.getElementById("salesSearchInput");
  const salesSearchButton = salesSearchForm?.querySelector(".search-btn");
  const salesSearchFeedback = document.getElementById("salesSearchFeedback");
  const salesRecordCount = document.getElementById("salesRecordCount");

  function syncSearchState(searchValue) {
    document
      .querySelectorAll('input[type="hidden"][name="search"]')
      .forEach((input) => {
        input.value = searchValue;
      });

    document.querySelectorAll('a[href*="search="]').forEach((link) => {
      try {
        const url = new URL(link.getAttribute("href"), window.location.href);
        url.searchParams.set("search", searchValue);
        link.setAttribute("href", url.pathname + url.search + url.hash);
      } catch (error) {
        console.warn("Unable to synchronize a sales filter link.", error);
      }
    });
  }

  function setSalesSearchLoading(isLoading) {
    salesGrid?.setAttribute("aria-busy", isLoading ? "true" : "false");
    salesGrid?.classList.toggle("is-loading", isLoading);

    if (salesSearchButton) {
      salesSearchButton.disabled = isLoading;
      salesSearchButton.classList.toggle("is-loading", isLoading);
      salesSearchButton.innerHTML = isLoading
        ? '<i class="bi bi-arrow-repeat" aria-hidden="true"></i>'
        : '<i class="bi bi-arrow-right-circle" aria-hidden="true"></i>';
      salesSearchButton.setAttribute(
        "aria-label",
        isLoading ? "Searching sales" : "Search sales",
      );
    }

    if (salesSearchFeedback) {
      if (isLoading) {
        salesSearchFeedback.textContent = "Searching sales…";
      } else if (salesSearchFeedback.textContent === "Searching sales…") {
        salesSearchFeedback.textContent = "";
      }
    }
  }

  salesSearchForm?.addEventListener("submit", async function (event) {
    event.preventDefault();
    if (!salesGrid || salesSearchButton?.disabled) {
      return;
    }

    const requestUrl = new URL(
      salesSearchForm.action || window.location.href,
      window.location.href,
    );
    requestUrl.search = new URLSearchParams(
      new FormData(salesSearchForm),
    ).toString();
    const searchValue = salesSearchInput?.value.trim() || "";

    setSalesSearchLoading(true);

    try {
      const response = await fetch(requestUrl.toString(), {
        method: "GET",
        credentials: "same-origin",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
        },
      });

      if (!response.ok) {
        throw new Error("The sales search could not be completed.");
      }

      if (
        response.redirected &&
        new URL(response.url).pathname !== requestUrl.pathname
      ) {
        window.location.assign(response.url);
        return;
      }

      const responseHtml = await response.text();
      const responseDocument = new DOMParser().parseFromString(
        responseHtml,
        "text/html",
      );
      const replacementGrid = responseDocument.getElementById("salesGrid");
      const replacementCount =
        responseDocument.getElementById("salesRecordCount");

      if (!replacementGrid || !replacementCount) {
        throw new Error("The server returned an incomplete sales list.");
      }

      salesGrid.innerHTML = replacementGrid.innerHTML;
      if (salesRecordCount) {
        salesRecordCount.textContent = replacementCount.textContent;
      }

      salesCurrentPage = 1;
      renderSalesPage();
      syncSearchState(searchValue);
      window.history.replaceState(
        {},
        "",
        requestUrl.pathname + requestUrl.search,
      );

      const recordCount = getSaleCards().length;
      if (salesSearchFeedback) {
        salesSearchFeedback.textContent = `${recordCount} sales record${recordCount === 1 ? "" : "s"} found.`;
      }
    } catch (error) {
      console.error("Sales search error:", error);
      showToast(
        error.message || "Unable to search sales. Please try again.",
        "error",
      );
    } finally {
      setSalesSearchLoading(false);
    }
  });

  /* ================= QUICK VIEW MODAL ================= */
  const quickViewModal = document.getElementById("quickViewModal");

  function paymentBadgeClass(status) {
    if (status === "Paid") {
      return "paid";
    }
    if (status === "Unpaid") {
      return "unpaid";
    }
    if (status === "Partially Paid") {
      return "partial";
    }
    return "";
  }

  function orderBadgeClass(status) {
    if (status === "Fulfilled") {
      return "fulfilled";
    }
    if (status === "Pending") {
      return "pending";
    }
    return "";
  }

  function openQuickView(btn) {
    const card = btn.closest(".sale-card");
    if (!card || !quickViewModal) {
      return;
    }

    const setText = (id, value) => {
      const el = document.getElementById(id);
      if (el) {
        el.textContent = value;
      }
    };

    setText("qvSalesNo", card.dataset.salesNo || "—");
    setText("qvDate", card.dataset.date || "—");
    setText("qvCashier", card.dataset.cashier || "—");
    setText("qvCustomer", card.dataset.customer || "Walk-in customer");
    setText("qvQty", card.dataset.qty || "0");
    setText("qvMethod", card.dataset.paymentMethod || "—");
    setText("qvItems", card.dataset.items || "—");
    setText("qvAmount", "₱" + (card.dataset.amount || "0.00"));

    const paymentBadge = document.getElementById("qvPaymentBadge");
    if (paymentBadge) {
      paymentBadge.textContent = card.dataset.paymentStatus || "—";
      paymentBadge.className =
        "badge " + paymentBadgeClass(card.dataset.paymentStatus);
    }

    const orderBadge = document.getElementById("qvOrderBadge");
    if (orderBadge) {
      orderBadge.textContent = card.dataset.orderStatus || "—";
      orderBadge.className =
        "badge " + orderBadgeClass(card.dataset.orderStatus);
    }

    const fullLink = document.getElementById("qvFullRecordLink");

    if (fullLink) {
      const saleUrl = new URL("sale_view.php", window.location.href);

      saleUrl.searchParams.set("id", card.dataset.id || "");

      // Preserve the current Sales Recording filter state.
      const currentParams = new URLSearchParams(window.location.search);

      [
        "filter",
        "search",
        "period",
        "ref_date",
        "date_from",
        "date_to",
        "cashier_id",
      ].forEach((key) => {
        if (currentParams.has(key)) {
          saleUrl.searchParams.set(key, currentParams.get(key));
        }
      });

      fullLink.href = saleUrl.pathname + saleUrl.search;
    }

    quickViewModal.classList.add("show");
    document.body.style.overflow = "hidden";
  }

  function closeQuickView() {
    if (!quickViewModal) {
      return;
    }
    quickViewModal.classList.remove("show");
    document.body.style.overflow = "auto";
  }

  if (quickViewModal) {
    quickViewModal.addEventListener("click", function (e) {
      if (e.target === quickViewModal) {
        closeQuickView();
      }
    });
  }

  /* ================= RECORDING WIZARD (step-based, no scroll/swipe) ================= */
  const wizardStepPanels = document.querySelectorAll(".wizard-step");
  const wizardDots = document.querySelectorAll(".wizard-step-dot");
  const wizardBackBtn = document.getElementById("wizardBackBtn");
  const wizardNextBtn = document.getElementById("wizardNextBtn");
  const reviewGrid = document.getElementById("reviewGrid");
  const reviewTotalEl = document.getElementById("reviewTotal");
  const WIZARD_TOTAL_STEPS = 3;
  var wizardCurrentStep = 1;

  function goToWizardStep(step) {
    step = Math.min(Math.max(step, 1), WIZARD_TOTAL_STEPS);
    wizardCurrentStep = step;

    wizardStepPanels.forEach((panel) => {
      panel.classList.toggle(
        "active",
        parseInt(panel.dataset.step, 10) === step,
      );
    });

    wizardDots.forEach((dot) => {
      const dotStep = parseInt(dot.getAttribute("data-goto"), 10);
      dot.classList.toggle("active", dotStep === step);
      dot.classList.toggle("done", dotStep < step);
    });

    if (wizardBackBtn) {
      wizardBackBtn.classList.toggle("hidden", step === 1);
    }
    if (wizardNextBtn) {
      wizardNextBtn.classList.toggle("hidden", step === WIZARD_TOTAL_STEPS);
    }
    if (saveSaleBtn) {
      saveSaleBtn.classList.toggle("hidden", step !== WIZARD_TOTAL_STEPS);
    }

    if (step === 3) {
      renderWizardReview();
    }

    if (saleModal?.classList.contains("show")) {
      const activePanel = Array.from(wizardStepPanels).find(
        (panel) => parseInt(panel.dataset.step, 10) === step,
      );
      activePanel?.scrollTo({ top: 0, behavior: "smooth" });
    }
  }

  function canOpenWizardStep(step) {
    if (step > 1 && !validateCustomerDetails()) {
      goToWizardStep(1);
      customerInfoCard?.scrollIntoView({ behavior: "smooth", block: "center" });
      return false;
    }

    if (step >= 3 && !document.querySelector(".item-row")) {
      showToast(
        "Add at least one product before reviewing the sale.",
        "warning",
      );
      goToWizardStep(2);
      productSearchInput?.focus();
      return false;
    }
    return true;
  }

  function renderWizardReview() {
    if (!reviewGrid) {
      return;
    }

    const methodEl = document.getElementById("paymentMethod");
    const salesNoEl = saleForm?.querySelector('input[name="sales_no"]');
    const cashierEl = document.getElementById("cashierDisplay");
    const paymentVal = paymentStatus ? paymentStatus.value : "—";
    const methodVal = methodEl ? methodEl.value : "—";
    const salesNoVal = salesNoEl?.value || "—";
    const cashierVal = cashierEl?.value || "Current User";
    const selectedCustomer = customersById.get(customerChoice?.value || "");
    const customerVal =
      selectedCustomer?.customer_name || customerNameInput?.value.trim() || "—";
    const dueDateVal = dueDateInput?.value || "—";

    const customerReviewRows = isCreditSale()
      ? `
            <div class="review-row review-row-customer"><i class="bi bi-person-vcard"></i><div><span>Customer</span><strong>${escapeHtml(customerVal)}</strong></div></div>
            <div class="review-row review-row-due-date"><i class="bi bi-calendar-check"></i><div><span>Due Date</span><strong>${escapeHtml(dueDateVal)}</strong></div></div>
        `
      : "";

    reviewGrid.innerHTML = `
            <div class="review-row review-row-status"><i class="bi bi-credit-card"></i><div><span>Payment Status</span><strong>${escapeHtml(paymentVal)}</strong></div></div>
            <div class="review-row review-row-method"><i class="bi bi-cash-coin"></i><div><span>Payment Method</span><strong>${escapeHtml(methodVal)}</strong></div></div>
            <div class="review-row review-row-sales-no"><i class="bi bi-hash"></i><div><span>Sales No.</span><strong>${escapeHtml(salesNoVal)}</strong></div></div>
            <div class="review-row review-row-cashier"><i class="bi bi-person-badge"></i><div><span>Cashier</span><strong>${escapeHtml(cashierVal)}</strong></div></div>
            ${customerReviewRows}
        `;

    const reviewItemsBody = document.getElementById("reviewItemsBody");
    let reviewItemsHtml = "";

    document.querySelectorAll(".item-row").forEach((row) => {
      const productId = row.dataset.productId;
      if (!productId || !productsById.has(productId)) {
        return;
      }

      const name = row.dataset.productName || "Product";
      const code = row.dataset.productCode || "No product code";
      const imageUrl =
        row.dataset.productImage || "../../IMAGES/default-product.svg";
      const qty = safeNumber(row.querySelector(".qty-input")?.value);
      const price = safeNumber(row.querySelector(".price-input")?.value);
      const discount = Math.min(
        Math.max(safeNumber(row.dataset.discount), 0),
        100,
      );
      const lineTotal = safeNumber(row.querySelector(".subtotal-input")?.value);

      reviewItemsHtml += `
        <div class="review-item-row">
          <div class="review-product-summary">
            <img src="${escapeHtml(imageUrl)}" alt="">
            <span><strong>${escapeHtml(name)}</strong><small>${escapeHtml(code)}</small></span>
          </div>
          <span data-label="Qty">${escapeHtml(qty)}</span>
          <span data-label="Unit Price">₱${formatMoney(price)}</span>
          <span data-label="Discount" class="review-item-discount">${discount > 0 ? `-${escapeHtml(discount)}%` : "—"}</span>
          <span data-label="Subtotal">₱${formatMoney(lineTotal)}</span>
        </div>`;
    });

    if (reviewItemsBody) {
      reviewItemsBody.innerHTML =
        reviewItemsHtml ||
        '<p class="review-items-empty">No products selected.</p>';
      reviewItemsBody.querySelectorAll("img").forEach((image) => {
        image.addEventListener(
          "error",
          function () {
            image.src = "../../IMAGES/default-product.svg";
          },
          { once: true },
        );
      });
    }

    const totals = getSaleTotals();
    const amountPaid = safeNumber(amountPaidInput?.value);
    const changeDue = updateChangeDue();
    const setMoney = (id, value) => {
      const element = document.getElementById(id);
      if (element) {
        element.textContent = formatMoney(value);
      }
    };

    setMoney("reviewSubtotal", totals.subtotal);
    setMoney("reviewDiscount", totals.discount);
    setMoney("reviewAmountPaid", amountPaid);
    setMoney("reviewChangeDue", changeDue);
    if (reviewTotalEl) {
      reviewTotalEl.textContent = formatMoney(totals.total);
    }

    const receiptInput = document.getElementById("receiptImageInput");
    const reviewReceiptFile = document.getElementById("reviewReceiptFile");
    if (reviewReceiptFile) {
      reviewReceiptFile.textContent =
        receiptInput?.files?.[0]?.name || "No receipt photo attached";
    }
  }

  if (wizardNextBtn) {
    wizardNextBtn.addEventListener("click", function () {
      const nextStep = wizardCurrentStep + 1;
      if (canOpenWizardStep(nextStep)) {
        goToWizardStep(nextStep);
      }
    });
  }

  if (wizardBackBtn) {
    wizardBackBtn.addEventListener("click", function () {
      goToWizardStep(wizardCurrentStep - 1);
    });
  }

  wizardDots.forEach((dot) => {
    dot.addEventListener("click", function () {
      const targetStep = parseInt(dot.getAttribute("data-goto"), 10) || 1;
      if (canOpenWizardStep(targetStep)) {
        goToWizardStep(targetStep);
      }
    });
  });

  /* ================= RECEIPT IMAGE ATTACH ================= */
  const receiptImageInput = document.getElementById("receiptImageInput");
  const attachReceiptText = document.getElementById("attachReceiptText");

  if (receiptImageInput && attachReceiptText) {
    receiptImageInput.addEventListener("change", function () {
      if (receiptImageInput.files && receiptImageInput.files[0]) {
        const file = receiptImageInput.files[0];
        if (file.size > 5 * 1024 * 1024) {
          receiptImageInput.value = "";
          attachReceiptText.textContent = "Attach Receipt Photo (optional)";
          showToast("Receipt photo must be 5MB or smaller.", "warning");
          return;
        }
        attachReceiptText.textContent = file.name;
      } else {
        attachReceiptText.textContent = "Attach Receipt Photo (optional)";
      }
      if (wizardCurrentStep === 3) {
        renderWizardReview();
      }
    });
  }

  window.openSaleModal = openSaleModal;
  window.closeSaleModal = closeSaleModal;
  window.calculateRow = calculateRow;
  window.removeRow = removeRow;
  window.openQuickView = openQuickView;
  window.closeQuickView = closeQuickView;
  window.goToWizardStep = goToWizardStep;

  updateItemsEmptyState();
  syncOrderStatusWithPayment();
  togglePaymentFields();
  syncCustomerSection();
  renderLiveReceipt();
  goToWizardStep(1);

  if (window.initialPopup && window.initialPopup.message) {
    showToast(window.initialPopup.message, window.initialPopup.type || "info");
  }
});
