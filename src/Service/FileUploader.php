<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Stores uploaded files under public/uploads/<folder>/ with safe, unique names.
 */
class FileUploader
{
    public function __construct(
        #[Autowire('%app.uploads_dir%')]
        private readonly string $uploadsDir,
        private readonly SluggerInterface $slugger,
        private readonly Filesystem $filesystem,
    ) {
    }

    /**
     * @return string the stored file name (without folder)
     */
    public function upload(UploadedFile $file, string $folder): string
    {
        $baseName = $this->slugger
            ->slug(pathinfo($file->getClientOriginalName(), \PATHINFO_FILENAME))
            ->lower()
            ->truncate(60)
            ->toString();

        $filename = \sprintf(
            '%s-%s.%s',
            '' !== $baseName ? $baseName : 'file',
            bin2hex(random_bytes(6)),
            $file->guessExtension() ?? 'bin',
        );

        $file->move($this->uploadsDir.'/'.$folder, $filename);

        return $filename;
    }

    public function remove(string $folder, ?string $filename): void
    {
        if (null === $filename || '' === $filename) {
            return;
        }

        $this->filesystem->remove($this->uploadsDir.'/'.$folder.'/'.basename($filename));
    }
}
