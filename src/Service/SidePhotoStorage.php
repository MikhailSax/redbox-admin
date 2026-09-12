<?php

namespace App\Service;

use App\Entity\ProductSide;
use App\Entity\ProductSidePhoto;
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

    public function remove(ProductSidePhoto $photo): void
    {
        $this->uploader->remove(ProductSidePhoto::UPLOAD_FOLDER, $photo->getFilename());
    }
}
