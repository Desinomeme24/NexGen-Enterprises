const loginModal = document.getElementById("loginModal");
const signupModal = document.getElementById("signupModal");

const openLoginBtn = document.getElementById("openLoginBtn");
const openLoginArea = document.getElementById("openLoginArea");
const openLoginFromMenu = document.getElementById("openLoginFromMenu");
const openLoginFromBot = document.getElementById("openLoginFromBot");

const closeLogin = document.getElementById("closeLogin");
const closeSignup = document.getElementById("closeSignup");

const goToSignup = document.getElementById("goToSignup");
const goToLogin = document.getElementById("goToLogin");

function openLoginModal() {
    if (loginModal) {loginModal.style.display = "flex";}
    if (signupModal) {signupModal.style.display = "none";}
}

function openSignupModal() {
    if (signupModal) {signupModal.style.display = "flex";}
    if (loginModal) {loginModal.style.display = "none";}
}

function closeModals() {
    if (loginModal) {loginModal.style.display = "none";}
    if (signupModal) {signupModal.style.display = "none";}
}

if (openLoginBtn) {
    openLoginBtn.addEventListener("click", function(e) {
        e.stopPropagation();
        openLoginModal();
    });
}

if (openLoginArea) {
    openLoginArea.addEventListener("click", function() {
        openLoginModal();
    });
}

if (openLoginFromMenu) {
    openLoginFromMenu.addEventListener("click", function() {
        openLoginModal();
    });
}

if (openLoginFromBot) {
    openLoginFromBot.addEventListener("click", function() {
        openLoginModal();
    });
}

if (closeLogin) {closeLogin.addEventListener("click", closeModals);}
if (closeSignup) {closeSignup.addEventListener("click", closeModals);}

if (goToSignup) {
    goToSignup.addEventListener("click", function(e) {
        e.preventDefault();
        openSignupModal();
    });
}

if (goToLogin) {
    goToLogin.addEventListener("click", function(e) {
        e.preventDefault();
        openLoginModal();
    });
}

window.addEventListener("click", function(e) {
    if (e.target === loginModal || e.target === signupModal) {
        closeModals();
    }
});

/* POPUP AUTO HIDE + CLICK TO CLOSE */
const popupOverlay = document.getElementById("popupOverlay");
const popupBox = document.getElementById("popupBox");

function closePopup() {
    if (popupOverlay && popupBox) {
        popupBox.classList.add("popup-hide");
        popupOverlay.classList.add("popup-overlay-hide");

        setTimeout(() => {
            popupOverlay.remove();
        }, 600);
    }
}

if (popupOverlay && popupBox) {
    setTimeout(() => {
        closePopup();
    }, 7000);

    popupOverlay.addEventListener("click", function() {
        closePopup();
    });

    popupBox.addEventListener("click", function(e) {
        e.stopPropagation();
    });
}

/* BACKGROUND CAROUSEL WITH DOTS */
const bgSlides = document.querySelectorAll(".bg-slide");
const dots = document.querySelectorAll(".dot");
let currentBgSlide = 0;
let bgInterval = null;

function showSlide(index) {
    bgSlides.forEach((slide, i) => {
        slide.classList.remove("active", "exit");

        if (i === currentBgSlide && i !== index) {
            slide.classList.add("exit");
        }
    });

    dots.forEach(dot => dot.classList.remove("active"));

    bgSlides[index].classList.add("active");
    dots[index].classList.add("active");

    currentBgSlide = index;
}

function nextSlide() {
    let nextIndex = currentBgSlide + 1;

    if (nextIndex >= bgSlides.length) {
        nextIndex = 0;
    }

    showSlide(nextIndex);
}

function startCarousel() {
    bgInterval = setInterval(() => {
        nextSlide();
    }, 3000);
}

function resetCarousel() {
    clearInterval(bgInterval);
    startCarousel();
}

if (bgSlides.length > 1 && dots.length === bgSlides.length) {
    dots.forEach((dot, index) => {
        dot.addEventListener("click", () => {
            showSlide(index);
            resetCarousel();
        });
    });

    startCarousel();
}