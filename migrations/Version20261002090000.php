<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002090000 extends AbstractMigration
{
    /** Screens that sell only part of their block: address words (all of them, in order) => slots on sale, and how many screen sides the address has */
    private const PARTIAL_SCREENS = [
        ['%Терешковой%', 9, 1],
        ['%Динамо%', 11, 1],
        ['%Сахьяновой%9/7%', 6, 1],
        ['%Кабанская%20%', 6, 2], // sides А and В
    ];

    public function getDescription(): string
    {
        return 'Screens: slots on sale apart from all the slots of the block';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_side ADD loop_slot_count INT DEFAULT NULL');

        foreach (self::PARTIAL_SCREENS as [$address, $onSale, $expected]) {
            $sides = $this->connection->fetchAllAssociative(
                "SELECT s.id, s.slot_count, p.name, s.name AS side FROM product_side s
                 JOIN product p ON p.id = s.product_id
                 JOIN product_type t ON t.id = p.product_type_id
                 LEFT JOIN product_type st ON st.id = s.product_type_id
                 WHERE p.name LIKE ? AND COALESCE(st.booking_mode, t.booking_mode) = 'airtime'",
                [$address],
            );
            if ([] === $sides || \count($sides) > $expected) {
                $this->write(\sprintf('<comment>%s: экранов найдено %d — слоты в продаже не изменены, укажите их в карточке</comment>', trim($address, '%'), \count($sides)));
                continue;
            }
            foreach ($sides as $side) {
                if ((int) $side['slot_count'] <= $onSale) {
                    continue;
                }
                $this->addSql('UPDATE product_side SET loop_slot_count = slot_count, slot_count = ? WHERE id = ?', [$onSale, (int) $side['id']]);
                $this->write(\sprintf('%s, сторона %s: в продаже %d из %d слотов', $side['name'], $side['side'], $onSale, $side['slot_count']));
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE product_side SET slot_count = loop_slot_count WHERE loop_slot_count IS NOT NULL');
        $this->addSql('ALTER TABLE product_side DROP loop_slot_count');
    }
}
