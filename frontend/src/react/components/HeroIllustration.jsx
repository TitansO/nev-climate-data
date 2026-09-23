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
    <img
      src="assets/images/hero/climate-finance-investment.jpg"
      alt="Illustration : globe de verre et jeunes arbres poussant sur des piles de pièces, symbole de la croissance du financement climatique"
      className="h-auto w-full max-w-[320px] rounded-2xl object-cover object-top shadow-card lg:h-[340px] lg:w-full lg:max-w-none"
    />
  );
}
