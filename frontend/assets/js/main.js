(function () {
  "use strict";

  // ===== Sticky header on scroll + logo swap (dark logo once opaque) =====
  window.onscroll = function () {
    const header = document.querySelector(".ud-header");
    if (!header) return;
    const sticky = header.offsetTop;
    const logo = document.querySelector(".header-logo");

    if (window.pageYOffset > sticky) {
      header.classList.add("sticky");
      if (logo) logo.src = "assets/images/logo/logo.svg";
    } else {
      header.classList.remove("sticky");
      if (logo) logo.src = "assets/images/logo/logo-white.svg";
    }
  };

  // ===== Responsive navbar toggle =====
  const navbarToggler = document.querySelector("#navbarToggler");
  const navbarCollapse = document.querySelector("#navbarCollapse");

  if (navbarToggler && navbarCollapse) {
    navbarToggler.addEventListener("click", () => {
      navbarToggler.classList.toggle("navbarTogglerActive");
      navbarCollapse.classList.toggle("hidden");
    });

    // Close the mobile menu once a nav link is clicked.
    document.querySelectorAll("#navbarCollapse ul li a").forEach((link) =>
      link.addEventListener("click", () => {
        navbarToggler.classList.remove("navbarTogglerActive");
        navbarCollapse.classList.add("hidden");
      })
    );
  }

  // ===== Mobile filters panel toggle (data.html) =====
  const filtersToggler = document.querySelector("#filtersToggler");
  const filtersPanel = document.querySelector("#filtersPanel");
  if (filtersToggler && filtersPanel) {
    filtersToggler.addEventListener("click", () => {
      filtersPanel.classList.toggle("hidden");
    });
  }
})();
