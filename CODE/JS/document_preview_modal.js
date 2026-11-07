document.addEventListener("DOMContentLoaded", function () {
  const modal = document.getElementById("documentPreviewModal");
  const frame = document.getElementById("documentPreviewFrame");
  const closeButton = document.getElementById("documentPreviewClose");

  if (!modal || !frame || !closeButton) {return;}

  let activeLink = null;
  let previousOverflow = "";

  function closePreview() {
    if (modal.hidden) {return;}
    modal.hidden = true;
    frame.src = "about:blank";
    document.body.style.overflow = previousOverflow;
    if (activeLink) {activeLink.focus();}
    activeLink = null;
  }

  document.addEventListener("click", function (event) {
    const link = event.target.closest("a[data-document-preview]");
    if (!link || event.defaultPrevented || event.button !== 0) {return;}
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {return;}

    event.preventDefault();
    activeLink = link;
    previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    frame.title = link.dataset.previewTitle || "Private document preview";

    const previewUrl = new URL(link.href, window.location.href);
    previewUrl.searchParams.set("preview", "1");
    frame.src = previewUrl.toString();
    modal.hidden = false;
    closeButton.focus();
  });

  closeButton.addEventListener("click", closePreview);
  modal.addEventListener("click", function (event) {
    if (event.target === modal) {closePreview();}
  });

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape" && !modal.hidden) {
      closePreview();
    }
  });

  window.addEventListener("message", function (event) {
    if (
      event.origin === window.location.origin
      && event.source === frame.contentWindow
      && event.data?.type === "nexgen-close-document-preview"
    ) {
      closePreview();
    }
  });
});
