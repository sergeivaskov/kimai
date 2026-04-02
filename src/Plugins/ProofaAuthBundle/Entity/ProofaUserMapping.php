<?php

namespace App\Plugins\ProofaAuthBundle\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use App\Plugins\ProofaAuthBundle\Repository\ProofaUserMappingRepository;

#[ORM\Entity(repositoryClass: ProofaUserMappingRepository::class)]
#[ORM\Table(name: 'proofa_user_mapping')]
class ProofaUserMapping
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $kimaiUser = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $affineId = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKimaiUser(): ?User
    {
        return $this->kimaiUser;
    }

    public function setKimaiUser(?User $kimaiUser): self
    {
        $this->kimaiUser = $kimaiUser;
        return $this;
    }

    public function getAffineId(): ?string
    {
        return $this->affineId;
    }

    public function setAffineId(string $affineId): self
    {
        $this->affineId = $affineId;
        return $this;
    }
}
