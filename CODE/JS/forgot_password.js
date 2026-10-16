const popupOverlay = document.getElementById("popupOverlay");

if (popupOverlay) {
    setTimeout(() => {
        popupOverlay.remove();
    }, 7000);

    popupOverlay.addEventListener("click", () => {
        popupOverlay.remove();
    });
}