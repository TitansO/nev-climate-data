<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * A3.13 : nature de la prestation demandée par un appel d'offres
 * (`Tender`) - catégories standard des marchés publics/privés
 * internationaux (Banque Mondiale, BAD, Nations Unies...), pas propres à
 * ce projet. Distinct d'`Opportunity` (F4) : un appel d'offres paie un
 * prestataire pour une prestation définie, un appel à projets finance le
 * projet d'un porteur - voir la docblock de `Tender` pour la distinction
 * complète.
 */
enum TenderCategory: string
{
    case Consulting = 'consulting';
    case Goods = 'goods';
    case Works = 'works';
    case Services = 'services';
}
