import { useI18n } from "../providers/I18nProvider";
import NevButton from "../components/ui/NevButton";

export default function NotFoundPage() {
  const { t } = useI18n();

  return (
    <section className="flex min-h-screen flex-col items-center justify-center bg-gradient-to-br from-deep via-deep-2 to-deep-3 px-4 text-center text-white">
      <p className="mb-4 text-sm font-semibold uppercase tracking-widest text-accent">{t("notFoundPage.error", "Erreur 404")}</p>
      <h1 className="mb-4 text-4xl font-bold sm:text-5xl">{t("notFoundPage.title", "Page introuvable")}</h1>
      <p className="mx-auto mb-10 max-w-[480px] text-white/80">
        {t("notFoundPage.subtitle", "La page que vous recherchez n'existe pas ou a été déplacée.")}
      </p>
      <NevButton as="a" href="index.html" variant="primary" size="md" className="px-7 py-3.5 text-base">
        {t("notFoundPage.backHome", "Retour à l'accueil")}
      </NevButton>
    </section>
  );
}
