(() => {
    const themeToggle = document.getElementById("theme-toggle");
    const themeStorageKey = "studysync-theme";

    if (!themeToggle) return;

    function applyTheme(theme) {
        document.body.dataset.theme = theme;
        const nextTheme = theme === "dark" ? "light" : "dark";
        themeToggle.textContent = `${nextTheme === "light" ? "Light" : "Dark"} theme`;
        themeToggle.setAttribute("aria-label", `Switch to ${nextTheme} theme`);
    }

    try {
        applyTheme(localStorage.getItem(themeStorageKey) === "light" ? "light" : "dark");
    } catch (error) {
        console.warn("Could not load the saved theme preference.", error);
        applyTheme("dark");
    }

    themeToggle.addEventListener("click", () => {
        const nextTheme = document.body.dataset.theme === "dark" ? "light" : "dark";
        applyTheme(nextTheme);
        try {
            localStorage.setItem(themeStorageKey, nextTheme);
        } catch (error) {
            console.warn("Could not save the theme preference.", error);
        }
    });
})();
