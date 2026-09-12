<?php

namespace App\Dto;

use App\Enum\ClientDocumentType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * "Upload documents" form: one ClientDocument per file.
 */
final class ClientDocumentUpload
{
    public const MAX_SIZE = '25M';

    public const MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/zip',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    #[Assert\NotNull(message: 'Выберите тип документа')]
    public ?ClientDocumentType $type = ClientDocumentType::Contract;

    /** Empty: each file is named after itself */
    #[Assert\Length(max: 255)]
    public ?string $title = null;

    /** @var list<UploadedFile> */
    #[Assert\Count(min: 1, minMessage: 'Выберите файл')]
    #[Assert\All([new Assert\File(maxSize: self::MAX_SIZE, mimeTypes: self::MIME_TYPES, mimeTypesMessage: 'PDF, Word, Excel, ZIP или изображение')])]
    public array $files = [];

    public ?string $comment = null;

    /** Title of the $index-th file: the given title (numbered when several files), or the file name */
    public function titleFor(UploadedFile $file, int $index): string
    {
        $title = trim((string) $this->title);
        if ('' === $title) {
            return mb_substr(pathinfo($file->getClientOriginalName(), \PATHINFO_FILENAME), 0, 255) ?: 'Документ';
        }

        return \count($this->files) > 1 ? \sprintf('%s (%d)', $title, $index + 1) : $title;
    }
}
