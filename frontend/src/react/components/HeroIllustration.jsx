/**
 * Homepage hero's right-column visual. No photographic asset was ever
 * fetched from the internet without a verified license (see the "Climate
 * NEV" template-integration plan) - was an original hand-authored SVG
 * placeholder, then a user-generated illustration (Gemini), now a
 * user-provided licensed stock photo (iStock,
 * `frontend/assets/images/hero/climate-finance-investment.jpg`) - a glass
 * globe and coin-stack saplings symbolizing climate finance growth. Kept
 * as its own component so a future asset swap never touches IndexPage.jsx.
 */
export default function HeroIllustration() {
  return (
    <div className="relative w-full max-w-[320px] lg:max-w-none">
      {/* Deux halos superposes plutot qu'un seul : la photo elle-meme est un
          bokeh vert vif/clair (proche du lime), tres different du vert tres
          sombre du fond du hero (--color-deep) - un seul halo dans la teinte
          "primary" du site restait trop fonce pour vraiment raccorder les
          deux. Le halo interne (plus clair, plus petit, colle a l'image)
          reprend la teinte dominante de la photo ; le halo externe (plus
          large, plus sombre) fait la transition vers le fond du hero. */}
      <div className="pointer-events-none absolute -inset-6 -z-10 rounded-full bg-[#84cc16]/30 blur-2xl" aria-hidden="true" />
      <div className="pointer-events-none absolute -inset-16 -z-10 rounded-full bg-primary-light/20 blur-3xl" aria-hidden="true" />
      <img
        src="assets/images/hero/climate-finance-investment.jpg"
        alt="Illustration : globe de verre et jeunes arbres poussant sur des piles de pièces, symbole de la croissance du financement climatique"
        className="h-auto w-full object-cover object-top lg:h-[340px] [mask-image:radial-gradient(ellipse_80%_80%_at_center,black_45%,transparent_100%)] [-webkit-mask-image:radial-gradient(ellipse_80%_80%_at_center,black_45%,transparent_100%)]"
      />
    </div>
  );
}
