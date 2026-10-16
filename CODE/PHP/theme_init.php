<script>
(function () {
    try {
        var savedTheme = localStorage.getItem("nexgen-theme");
        document.documentElement.setAttribute(
            "data-theme",
            savedTheme === "light" ? "light" : "dark"
        );
    } catch (e) {
        document.documentElement.setAttribute("data-theme", "dark");
    }
})();
</script>
