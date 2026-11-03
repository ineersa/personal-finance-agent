<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ReportingCurrency;
use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[UniqueEntity('email')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $chatGptIdentity = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $email = '';

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(length: 3, enumType: ReportingCurrency::class, options: ['default' => 'USD'])]
    private ReportingCurrency $reportingCurrency = ReportingCurrency::USD;

    #[ORM\Column(options: ['default' => 'en_US'])]
    #[Assert\NotBlank]
    #[Assert\Locale]
    private string $moneyLocale = 'en_US';

    /**
     * Symfony's session-only password fingerprint, not the stored password hash.
     * Database passwords use the configured Symfony auto hasher.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);

        return $data;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChatGptIdentity(): ?string
    {
        return $this->chatGptIdentity;
    }

    public function initializeChatGptIdentity(): string
    {
        return $this->chatGptIdentity ??= Uuid::v4()->toRfc4122();
    }

    public function renewChatGptIdentity(): string
    {
        return $this->chatGptIdentity = Uuid::v4()->toRfc4122();
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getReportingCurrency(): ReportingCurrency
    {
        return $this->reportingCurrency;
    }

    public function setReportingCurrency(ReportingCurrency $currency): static
    {
        $this->reportingCurrency = $currency;

        return $this;
    }

    public function getMoneyLocale(): string
    {
        return $this->moneyLocale;
    }

    public function setMoneyLocale(string $locale): static
    {
        $this->moneyLocale = $locale;

        return $this;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        if (!\in_array('ROLE_ADMIN', $roles, true)) {
            $roles[] = 'ROLE_USER';
        }

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }
}
