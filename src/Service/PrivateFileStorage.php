<?php

namespace App\Service;

use App\Dto\StoredFile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Files that must not be reachable by URL (client contracts, photo reports): kept under var/storage,
 * outside the web root, and handed out by controllers after an access check.
 */
class PrivateFileStorage
{
    public function __construct(
        #[Autowire('%app.private_storage_dir%')]
        private readonly string $storageDir,
        private readonly SluggerInterface $slugger,
        private readonly Filesystem $filesystem,
    ) {
    }

    public function store(UploadedFile $file, string $folder): StoredFile
    {
        $originalName = $file->getClientOriginalName();
        $mimeType = $file->getMimeType() ?? 'application/octet-stream';
        $size = (int) $file->getSize();

        $baseName = $this->slugger->slug(pathinfo($originalName, \PATHINFO_FILENAME))->lower()->truncate(60)->toString();
        $extension = $file->guessExtension() ?? (mb_strtolower($file->getClientOriginalExtension()) ?: 'bin');
        $fileName = \sprintf('%s-%s.%s', '' !== $baseName ? $baseName : 'file', bin2hex(random_bytes(6)), $extension);

        $file->move($this->storageDir.'/'.$folder, $fileName);

        return new StoredFile($fileName, mb_substr($originalName, 0, 255), $mimeType, $size);
    }

    public function path(string $folder, string $fileName): string
    {
        return $this->storageDir.'/'.$folder.'/'.basename($fileName);
    }

    public function remove(string $folder, string $fileName): void
    {
        $this->filesystem->remove($this->path($folder, $fileName));
    }

    /** Removes a whole folder, e.g. everything of a deleted client */
    public function removeFolder(string $folder): void
    {
        $this->filesystem->remove($this->storageDir.'/'.$folder);
    }
}
