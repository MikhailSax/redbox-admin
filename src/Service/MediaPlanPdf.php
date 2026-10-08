<?php

namespace App\Service;

use App\Entity\MediaPlan;
use App\Entity\MediaPlanItem;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\String\Slugger\SluggerInterface;
use Twig\Environment;

/**
 * Commercial proposal PDF for a client (dompdf; DejaVu Sans covers Cyrillic).
 */
class MediaPlanPdf
{
    private const PHOTO_WIDTH = 900;
    // the photo frame on the page, mm: a photo is fitted inside it keeping its proportions
    private const FRAME_WIDTH = 50;
    private const FRAME_HEIGHT = 31;

    public function __construct(
        private readonly Environment $twig,
        private readonly SluggerInterface $slugger,
        #[Autowire('%app.uploads_dir%')]
        private readonly string $uploadsDir,
        #[Autowire('%kernel.project_dir%/public/images/brand/logo-180.png')]
        private readonly string $logoPath,
    ) {
    }

    public function render(MediaPlan $plan): string
    {
        $photos = [];
        foreach ($plan->getItems() as $item) {
            $photos[$item->getId()] = $this->photoFor($item);
        }

        $html = $this->twig->render('admin/media_plan/pdf.html.twig', [
            'plan' => $plan,
            'photos' => $photos,
            'logo' => is_file($this->logoPath) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($this->logoPath)) : null,
            'generatedAt' => new \DateTimeImmutable(),
        ]);

        $options = (new Options())
            ->setDefaultFont('DejaVu Sans')
            ->setIsRemoteEnabled(false); // everything is inline, never fetch URLs

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * "Mediaplan-Kafe-Leto.pdf": ASCII fallback for the download name.
     */
    public function asciiFilename(MediaPlan $plan): string
    {
        return 'Mediaplan-'.$this->slugger->slug((string) $plan->getTitle())->truncate(60).'.pdf';
    }

    /**
     * First photo of the side (or of the structure), downscaled and inlined as a JPEG data URI,
     * with its size in mm fitted into the frame without stretching.
     *
     * @return array{src: string, width: float, height: float, top: float}|null
     */
    private function photoFor(MediaPlanItem $item): ?array
    {
        $photo = $item->getSide()->getPhotos()->first() ?: $item->getProduct()?->getCoverPhoto();
        if (!$photo) {
            return null;
        }

        $path = $this->uploadsDir.'/'.$photo::UPLOAD_FOLDER.'/'.$photo->getFilename();
        $data = is_file($path) ? file_get_contents($path) : false;
        $image = false !== $data ? @imagecreatefromstring($data) : false;
        if (false === $image) {
            return null;
        }

        if (imagesx($image) > self::PHOTO_WIDTH) {
            $image = imagescale($image, self::PHOTO_WIDTH) ?: $image;
        }

        ob_start();
        imagejpeg($image, null, 80);
        $src = 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());

        $scale = min(self::FRAME_WIDTH / imagesx($image), self::FRAME_HEIGHT / imagesy($image));
        $width = round(imagesx($image) * $scale, 2);
        $height = round(imagesy($image) * $scale, 2);

        return ['src' => $src, 'width' => $width, 'height' => $height, 'top' => round((self::FRAME_HEIGHT - $height) / 2, 2)];
    }
}
