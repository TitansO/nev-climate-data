import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { AuthProvider } from "../providers/AuthProvider";
import { I18nProvider } from "../providers/I18nProvider";
import Navbar from "../components/Navbar";
import Footer from "../components/Footer";
import SourcesPage from "../pages/SourcesPage";
import "../../input.css";

createRoot(document.getElementById("root")).render(
  <StrictMode>
    <I18nProvider>
      <AuthProvider>
        <Navbar activeHref="sources.html" />
        <SourcesPage />
        <Footer />
      </AuthProvider>
    </I18nProvider>
  </StrictMode>
);
