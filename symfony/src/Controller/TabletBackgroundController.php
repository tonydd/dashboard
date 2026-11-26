<?php

namespace App\Controller;

use App\Enum\KeyValueKey;
use App\Repository\KeyValueRepository;
use App\Service\TabletBackgroundService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class TabletBackgroundController extends AbstractController
{
    public function __construct(
        private readonly TabletBackgroundService $backgrounds,
        private readonly KeyValueRepository $keyValues,
    ) {
    }

    #[Route('/tablet-background', name: 'tablet_background_settings', methods: ['GET'])]
    public function settings(): Response
    {
        if (!$this->getUser()) {
            return $this->render('dashboard/login.html.twig');
        }

        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('dashboard/tablet_background.html.twig', [
            'background' => $this->backgrounds->get(),
            'updatedAt' => $this->keyValues->getUpdatedAt(KeyValueKey::TabletBackground),
            'error' => null,
        ]);
    }

    #[Route('/tablet-background', name: 'tablet_background_save', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[IsCsrfTokenValid('tablet_background')]
    public function save(Request $request): Response
    {
        if ($request->request->get('action') === 'clear') {
            $this->backgrounds->clear();

            return $this->redirectToRoute('tablet_background_settings');
        }

        $url = trim((string) $request->request->get('url', ''));
        $upload = $request->files->get('image');

        try {
            if ($upload instanceof UploadedFile && !$upload->isValid()) {
                return $this->renderError('L’envoi du fichier a échoué (fichier trop volumineux ?).');
            }
            if ($upload instanceof UploadedFile) {
                $this->backgrounds->setImage(
                    (string) file_get_contents($upload->getPathname()),
                    $this->detectMimeType($upload),
                );
            } elseif ($url !== '') {
                $this->backgrounds->setUrl($url);
            } else {
                return $this->renderError('Indiquez une URL ou choisissez un fichier image.');
            }
        } catch (\InvalidArgumentException $exception) {
            return $this->renderError($exception->getMessage());
        }

        return $this->redirectToRoute('tablet_background_settings');
    }

    #[Route('/api/tablet-background/image', name: 'api_tablet_background_image', methods: ['GET'])]
    public function image(): Response
    {
        $background = $this->backgrounds->get();
        if ($background === null) {
            throw $this->createNotFoundException('No tablet background is configured.');
        }

        if ($background['type'] === 'url') {
            return $this->redirect($background['url']);
        }

        return new Response(
            base64_decode($background['data'], true),
            Response::HTTP_OK,
            [
                'Content-Type' => $background['mimeType'],
                // Reloaded on each /tablet visit so updates apply immediately.
                'Cache-Control' => 'no-store',
            ],
        );
    }

    private function renderError(string $message): Response
    {
        $response = $this->render('dashboard/tablet_background.html.twig', [
            'background' => $this->backgrounds->get(),
            'updatedAt' => $this->keyValues->getUpdatedAt(KeyValueKey::TabletBackground),
            'error' => $message,
        ]);
        $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);

        return $response;
    }

    private function detectMimeType(UploadedFile $upload): string
    {
        $binary = (string) file_get_contents($upload->getPathname());

        return (new \finfo(FILEINFO_MIME_TYPE))->buffer($binary) ?: '';
    }
}
