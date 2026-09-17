<?php

namespace App\Service;

use App\Entity\ProductSide;
use App\Entity\ProductSidePhoto;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stores uploaded side photos on disk and removes them.
 */
class SidePhotoStorage
{
    public function __construct(
        private readonly FileUploader $uploader,
    ) {
    }

    /**
     * Moves the uploaded file into storage and attaches a new photo to the side.
     * The photo is persisted together with the side (cascade persist).
     */
    public function attach(ProductSide $side, UploadedFile $file): ProductSidePhoto
    {
        $filename = $this->uploader->upload($file, ProductSidePhoto::UPLOAD_FOLDER);

        $photo = new ProductSidePhoto($filename, $file->getClientOriginalName());
        $side->addPhoto($photo);

        return $photo;
    }

    /**
     * Copies a file from outside a form (a downloaded photo) into storage and attaches it to the side,
     * unless the side already has a photo with that original name or the same content.
     *
     * @return ProductSidePhoto|null null when the side already has it
     */
    public function attachCopy(ProductSide $side, File $file, string $originalName): ?ProductSidePhoto
    {
        $hash = md5_file($file->getPathname());
        foreach ($side->getPhotos() as $existing) {
            $path = $this->uploader->path(ProductSidePhoto::UPLOAD_FOLDER, $existing->getFilename());
            if ($existing->getOriginalName() === $originalName || (is_file($path) && md5_file($path) === $hash)) {
                return null;
            }
        }

        $photo = new ProductSidePhoto($this->uploader->copy($file, ProductSidePhoto::UPLOAD_FOLDER, $originalName), $originalName);
        $side->addPhoto($photo);

        return $photo;
    }

    public function remove(ProductSidePhoto $photo): void
    {
        $this->uploader->remove(ProductSidePhoto::UPLOAD_FOLDER, $photo->getFilename());
    }
}
