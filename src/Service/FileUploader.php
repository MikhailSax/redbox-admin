<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
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
        $filename = $this->filename($file, $file->getClientOriginalName());
        $file->move($this->uploadsDir.'/'.$folder, $filename);

        return $filename;
    }

    /**
     * Copies a file that was not uploaded through a form (e.g. downloaded by an import); the source stays in place.
     *
     * @return string the stored file name (without folder)
     */
    public function copy(File $file, string $folder, string $originalName): string
    {
        $filename = $this->filename($file, $originalName);
        $this->filesystem->copy($file->getPathname(), $this->path($folder, $filename));

        return $filename;
    }

    public function path(string $folder, string $filename): string
    {
        return $this->uploadsDir.'/'.$folder.'/'.basename($filename);
    }

    private function filename(File $file, string $originalName): string
    {
        $baseName = $this->slugger
            ->slug(pathinfo($originalName, \PATHINFO_FILENAME))
            ->lower()
            ->truncate(60)
            ->toString();

        return \sprintf(
            '%s-%s.%s',
            '' !== $baseName ? $baseName : 'file',
            bin2hex(random_bytes(6)),
            $file->guessExtension() ?? 'bin',
        );
    }

    public function remove(string $folder, ?string $filename): void
    {
        if (null === $filename || '' === $filename) {
            return;
        }

        $this->filesystem->remove($this->path($folder, $filename));
    }
}
