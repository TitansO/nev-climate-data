<?php

declare(strict_types=1);

namespace App\Service\Scoring;

/**
 * F3 (fondation du sprint C1-C3) : un indicateur, unité de base de tout
 * score C2/C3 (bancabilité, alignement CDN, risque investissement,
 * équité, transparence...) - voir App\Service\Scoring\ScoreService, qui
 * combine une liste d'indicateurs en un score 0-100 + grade A-E.
 *
 * `compute()` renvoie toujours une valeur normalisée 0.0-1.0 (jamais un
 * montant brut ni un pourcentage 0-100) : c'est ce qui permet à
 * ScoreService de combiner des indicateurs de nature complètement
 * différente (un ratio, une présence booléenne, un coefficient de
 * variation...) par une simple somme pondérée.
 *
 * @template T
 */
interface ScoreIndicatorInterface
{
    /** Identifiant stable de l'indicateur, persisté dans Score::$breakdown - jamais renommé une fois utilisé en données réelles. */
    public function code(): string;

    /** Poids de cet indicateur dans le score final - la somme des poids de tous les indicateurs d'un score doit valoir 1.0. */
    public function weight(): float;

    /**
     * @param T $subject
     *
     * @return float 0.0 (le plus défavorable) à 1.0 (le plus favorable) - jamais négatif, jamais au-delà de 1.0
     */
    public function compute(mixed $subject): float;

    /**
     * Explication humainement lisible de la valeur renvoyée par compute()
     * pour ce $subject précis - ce qui rend Score::$breakdown auditable
     * plutôt qu'une simple liste de nombres.
     *
     * @param T $subject
     */
    public function explain(mixed $subject): string;
}
