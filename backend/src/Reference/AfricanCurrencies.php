<?php

declare(strict_types=1);

namespace App\Reference;

/**
 * Static reference data for the "amount in each country's local currency"
 * column (data.html) - not fetched from a live API, no such dependency
 * exists in this project. Two tables:
 *
 * - COUNTRY_CURRENCY: ISO 3166-1 alpha-3 country code -> ISO 4217 currency
 *   code, one entry per country seeded by CountryFixtures (the 54 UN
 *   member states of Africa).
 * - LOCAL_UNITS_PER_USD: ISO 4217 currency code -> units of that currency
 *   per 1 USD (e.g. 600.0 for XOF: 600 XOF buys 1 USD) - the natural,
 *   real-world-checkable direction. To go from a USD (pivot) amount to
 *   that currency, multiply by this value, not divide - dividing was a
 *   real bug caught while building this (see FundingFixtures::load()).
 *   Approximate, illustrative rates (a single fixed point in time, not
 *   updated automatically) - consistent with the rest of this dataset
 *   already being explicitly labeled "Démonstration" (A1.6). A currency
 *   shared by several countries (XOF/XAF, the CFA francs) appears once
 *   and is reused across all of them.
 *
 * Real exchange-rate sourcing belongs to Volet B's pipeline (the
 * "devise pivot" conversion governance rule, B1.6) - this class only
 * backs the Volet A demo/reference dataset.
 */
final class AfricanCurrencies
{
    /**
     * @var array<string, string>
     */
    public const COUNTRY_CURRENCY = [
        // Afrique du Nord
        'DZA' => 'DZD',
        'EGY' => 'EGP',
        'LBY' => 'LYD',
        'MAR' => 'MAD',
        'SDN' => 'SDG',
        'TUN' => 'TND',

        // Afrique de l'Ouest
        'BEN' => 'XOF',
        'BFA' => 'XOF',
        'CPV' => 'CVE',
        'CIV' => 'XOF',
        'GMB' => 'GMD',
        'GHA' => 'GHS',
        'GIN' => 'GNF',
        'GNB' => 'XOF',
        'LBR' => 'LRD',
        'MLI' => 'XOF',
        'MRT' => 'MRU',
        'NER' => 'XOF',
        'NGA' => 'NGN',
        'SEN' => 'XOF',
        'SLE' => 'SLE',
        'TGO' => 'XOF',

        // Afrique centrale
        'AGO' => 'AOA',
        'CMR' => 'XAF',
        'CAF' => 'XAF',
        'TCD' => 'XAF',
        'COG' => 'XAF',
        'COD' => 'CDF',
        'GNQ' => 'XAF',
        'GAB' => 'XAF',
        'STP' => 'STN',

        // Afrique de l'Est
        'BDI' => 'BIF',
        'COM' => 'KMF',
        'DJI' => 'DJF',
        'ERI' => 'ERN',
        'ETH' => 'ETB',
        'KEN' => 'KES',
        'MDG' => 'MGA',
        'MWI' => 'MWK',
        'MUS' => 'MUR',
        'MOZ' => 'MZN',
        'RWA' => 'RWF',
        'SYC' => 'SCR',
        'SOM' => 'SOS',
        'SSD' => 'SSP',
        'TZA' => 'TZS',
        'UGA' => 'UGX',
        'ZMB' => 'ZMW',
        'ZWE' => 'ZWG',

        // Afrique australe
        'BWA' => 'BWP',
        'SWZ' => 'SZL',
        'LSO' => 'LSL',
        'NAM' => 'NAD',
        'ZAF' => 'ZAR',
    ];

    /**
     * @var array<string, float>
     */
    public const LOCAL_UNITS_PER_USD = [
        'DZD' => 134.5,
        'EGP' => 48.5,
        'LYD' => 4.85,
        'MAD' => 9.9,
        'SDG' => 601.0,
        'TND' => 3.1,
        'XOF' => 600.0,
        'CVE' => 100.5,
        'GMD' => 71.0,
        'GHS' => 14.5,
        'GNF' => 8600.0,
        'LRD' => 190.0,
        'MRU' => 39.8,
        'NGN' => 1550.0,
        'SLE' => 22.7,
        'AOA' => 920.0,
        'XAF' => 600.0,
        'CDF' => 2850.0,
        'STN' => 22.3,
        'BIF' => 2900.0,
        'KMF' => 448.0,
        'DJF' => 178.0,
        'ERN' => 15.0,
        'ETB' => 123.0,
        'KES' => 129.0,
        'MGA' => 4450.0,
        'MWK' => 1735.0,
        'MUR' => 45.5,
        'MZN' => 63.9,
        'RWF' => 1330.0,
        'SCR' => 13.6,
        'SOS' => 571.0,
        'SSP' => 4500.0,
        'TZS' => 2600.0,
        'UGX' => 3700.0,
        'ZMW' => 27.0,
        'ZWG' => 26.9,
        'BWP' => 13.6,
        'SZL' => 18.3,
        'LSL' => 18.3,
        'NAD' => 18.3,
        'ZAR' => 18.3,
    ];

    private function __construct()
    {
        // Static reference data only - never instantiated.
    }

    public static function currencyForCountry(string $isoCode): ?string
    {
        return self::COUNTRY_CURRENCY[$isoCode] ?? null;
    }

    public static function localUnitsPerUsd(string $currencyCode): ?float
    {
        return self::LOCAL_UNITS_PER_USD[$currencyCode] ?? null;
    }
}
