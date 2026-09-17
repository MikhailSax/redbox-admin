<?php

namespace App\Service\Import;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads structure photos linked from the address programme into temporary files.
 * Each link is fetched once per run; call cleanup() when the files have been copied into storage.
 */
class PhotoDownloader
{
    private const MAX_BYTES = 20 * 1024 * 1024;

    /** @var array<string, File|null> url => downloaded image */
    private array $files = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly Filesystem $filesystem,
    ) {
    }

    /**
     * @return File|null null when the link does not answer with an image
     */
    public function download(string $url): ?File
    {
        if (\array_key_exists($url, $this->files)) {
            return $this->files[$url];
        }

        $path = $this->filesystem->tempnam(sys_get_temp_dir(), 'side-photo-');
        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 30]);
            if (200 !== $response->getStatusCode()) {
                throw new \RuntimeException();
            }
            $handle = fopen($path, 'w');
            try {
                $bytes = 0;
                foreach ($this->httpClient->stream($response) as $chunk) {
                    $bytes += fwrite($handle, $chunk->getContent());
                    if ($bytes > self::MAX_BYTES) {
                        $response->cancel();
                        break;
                    }
                }
            } finally {
                fclose($handle);
            }

            $file = new File($path);
            $isImage = $bytes <= self::MAX_BYTES && str_starts_with((string) MimeTypes::getDefault()->guessMimeType($path), 'image/');
        } catch (ExceptionInterface|\RuntimeException) {
            $isImage = false;
        }

        if (!$isImage) {
            $this->filesystem->remove($path);

            return $this->files[$url] = null;
        }

        return $this->files[$url] = $file;
    }

    /** "…/medialibrary/84b/03z3yl78.jpg?x=1" → "03z3yl78.jpg" */
    public static function originalName(string $url): string
    {
        return rawurldecode(basename((string) parse_url($url, \PHP_URL_PATH)));
    }

    public function cleanup(): void
    {
        foreach ($this->files as $file) {
            if (null !== $file) {
                $this->filesystem->remove($file->getPathname());
            }
        }
        $this->files = [];
    }
}
