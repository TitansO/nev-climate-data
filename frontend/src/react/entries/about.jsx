import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { AuthProvider } from "../providers/AuthProvider";
import { I18nProvider } from "../providers/I18nProvider";
import Navbar from "../components/Navbar";
import Footer from "../components/Footer";
import AboutPage from "../pages/AboutPage";
import "../../input.css";

createRoot(document.getElementById("root")).render(
  <StrictMode>
    <I18nProvider>
      <AuthProvider>
        <Navbar activeHref="about.html" />
        <AboutPage />
        <Footer />
      </AuthProvider>
    </I18nProvider>
  </StrictMode>
);
