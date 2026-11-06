<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Schema/fixture mapping only; all runtime history I/O belongs to DoctrineDbalMessageStore. */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: self::TABLE)]
class ChatMessages
{
    public const string TABLE = 'chat_messages';

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    public string $id;

    #[ORM\Column(length: 36)]
    public string $version;

    #[ORM\Column(type: 'text')]
    public string $messages;

    #[ORM\Column(type: 'integer')]
    public int $updatedAt;
}
