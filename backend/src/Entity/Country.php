<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CountryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CountryRepository::class)]
class Country
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 3, unique: true)]
    private string $isoCode;

    #[ORM\Column(length: 255)]
    private string $region;

    // ISO 4217 code of the country's own national currency (e.g. "XOF" for
    // Senegal, "ZAR" for South Africa) - not the pivot currency Funding
    // amounts are normalized into (always USD, see Funding::$amount vs
    // Funding::$originalAmount/$originalCurrency). Nullable only because a
    // handful of pre-A2.x rows may predate this column; every row seeded by
    // CountryFixtures has one.
    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currency = null;

    public function __construct(string $name, string $isoCode, string $region, ?string $currency = null)
    {
        $this->name = $name;
        $this->isoCode = $isoCode;
        $this->region = $region;
        $this->currency = $currency;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getIsoCode(): string
    {
        return $this->isoCode;
    }

    public function setIsoCode(string $isoCode): static
    {
        $this->isoCode = $isoCode;

        return $this;
    }

    public function getRegion(): string
    {
        return $this->region;
    }

    public function setRegion(string $region): static
    {
        $this->region = $region;

        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): static
    {
        $this->currency = $currency;

        return $this;
    }
}
