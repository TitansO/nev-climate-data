import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { I18nProvider } from "../providers/I18nProvider";
import MinimalHeader from "../components/MinimalHeader";
import NotFoundPage from "../pages/NotFoundPage";
import "../../input.css";

createRoot(document.getElementById("root")).render(
  <StrictMode>
    <I18nProvider>
      <MinimalHeader />
      <NotFoundPage />
    </I18nProvider>
  </StrictMode>
);
