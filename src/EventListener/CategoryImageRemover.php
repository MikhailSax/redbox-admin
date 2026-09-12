<?php

namespace App\EventListener;

use App\Entity\Category;
use App\Service\FileUploader;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * Deletes the category image from disk once the category is removed.
 */
#[AsEntityListener(event: Events::postRemove, method: 'postRemove', entity: Category::class)]
final class CategoryImageRemover
{
    public function __construct(
        private readonly FileUploader $uploader,
    ) {
    }

    public function postRemove(Category $category): void
    {
        $this->uploader->remove(Category::UPLOAD_FOLDER, $category->getImage());
    }
}
