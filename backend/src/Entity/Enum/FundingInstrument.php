<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * F1 (fondation du sprint C1-C3) : type d'instrument financier d'un
 * financement, distinct de `FundingType` (qui décrit la nature du
 * bailleur - public/privé/multilatéral, pas la forme de l'argent).
 * Alimenté aujourd'hui par les connecteurs IATI (GCF/BAD, via le champ
 * `default-finance-type`) - voir `pipeline/processors/instrument_mapping.py`.
 * World Bank et OPEC Fund PDF ne portent aucun signal d'instrument dans
 * leurs messages actuels et laissent systématiquement `Unknown`.
 */
enum FundingInstrument: string
{
    case Grant = 'grant';
    case LoanConcessional = 'loan_concessional';
    case LoanNonConcessional = 'loan_non_concessional';
    case Guarantee = 'guarantee';
    case Equity = 'equity';
    case ResultsBased = 'results_based';
    case Unknown = 'unknown';
}
