<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductReservationItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductReservationItemRepository::class)]
#[ORM\Table(name: 'product_reservation_item')]
#[ORM\Index(name: 'IDX_PRODUCT_RESERVATION_ITEM_RESERVATION', columns: ['reservation_id'])]
#[ORM\Index(name: 'IDX_PRODUCT_RESERVATION_ITEM_PRODUCT', columns: ['product_id'])]
class ProductReservationItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ProductReservation $reservation = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Product $product = null;

    #[ORM\Column(length: 140)]
    private string $productName = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoPath = null;

    #[ORM\Column]
    private int $unitNormalPriceCents = 0;

    #[ORM\Column]
    private int $unitPromotionalPriceCents = 0;

    #[ORM\Column]
    private int $quantity = 1;

    /** @var list<array{label: string, value: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $customAttributes = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReservation(): ?ProductReservation
    {
        return $this->reservation;
    }

    public function setReservation(ProductReservation $reservation): self
    {
        $this->reservation = $reservation;

        return $this;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): self
    {
        $this->product = $product;

        return $this;
    }

    public function getProductName(): string
    {
        return $this->productName;
    }

    public function setProductName(string $productName): self
    {
        $this->productName = trim($productName);

        return $this;
    }

    public function getPhotoPath(): ?string
    {
        return $this->photoPath;
    }

    public function setPhotoPath(?string $photoPath): self
    {
        $photoPath = $photoPath !== null ? trim($photoPath) : null;
        $this->photoPath = $photoPath !== '' ? $photoPath : null;

        return $this;
    }

    public function getUnitNormalPriceCents(): int
    {
        return $this->unitNormalPriceCents;
    }

    public function setUnitNormalPriceCents(int $unitNormalPriceCents): self
    {
        $this->unitNormalPriceCents = max(0, $unitNormalPriceCents);

        return $this;
    }

    public function getUnitPromotionalPriceCents(): int
    {
        return $this->unitPromotionalPriceCents;
    }

    public function setUnitPromotionalPriceCents(int $unitPromotionalPriceCents): self
    {
        $this->unitPromotionalPriceCents = max(0, $unitPromotionalPriceCents);

        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): self
    {
        $this->quantity = max(1, $quantity);

        return $this;
    }

    public function getLineTotalCents(): int
    {
        return $this->unitPromotionalPriceCents * $this->quantity;
    }

    /** @return list<array{label: string, value: string}> */
    public function getCustomAttributes(): array
    {
        return $this->customAttributes;
    }

    /** @param list<array{label: string, value: string}> $customAttributes */
    public function setCustomAttributes(array $customAttributes): self
    {
        $this->customAttributes = $customAttributes;

        return $this;
    }
}
