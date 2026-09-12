<?php

namespace App\EventListener;

use App\Entity\ProductSidePhoto;
use App\Service\SidePhotoStorage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * Deletes the file from disk once its photo row is removed
 * (directly, via orphan removal of a side, or via cascade from a product).
 */
#[AsEntityListener(event: Events::postRemove, method: 'postRemove', entity: ProductSidePhoto::class)]
final class SidePhotoFileRemover
{
    public function __construct(
        private readonly SidePhotoStorage $storage,
    ) {
    }

    public function postRemove(ProductSidePhoto $photo): void
    {
        $this->storage->remove($photo);
    }
}
