<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\ApiPresenter;
use App\Http\JsonPayload;
use App\Http\RequestContext;
use App\Service\GroceryReturn\GroceryReturnResolver;
use App\Service\GroceryReturn\GroceryReturnService;
use App\Service\GroceryReturn\ReceiptReader;
use App\Service\GroceryReturn\ResolvedGroceryLine;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The grocery return: the basket of a shopping trip, filled in by the server
 * and written as one operation.
 *
 * The basket itself stays on the phone, like the preview of an imported
 * recipe: `resolve` is a stateless preview that writes nothing, `confirm`
 * is the single write. Nothing in between needs a server-side object.
 */
#[Route('/api/grocery-returns')]
class GroceryReturnController extends AbstractApiController
{
    /**
     * Fills every empty field of the lines from the household's habits, the
     * catalogue and the lexicons, and names the one question a line may still
     * ask. Sent with one line after an edit, or with the whole basket.
     */
    #[Route('/resolve', methods: ['POST'])]
    public function resolve(
        Request $request,
        RequestContext $context,
        GroceryReturnResolver $resolver,
        ApiPresenter $presenter,
    ): JsonResponse {
        $payload = JsonPayload::fromRequest($request);
        $lines = $resolver->resolve($context->household(), $payload->objectList('lines'));

        return $this->json([
            'lines' => array_map(
                static fn (ResolvedGroceryLine $line): array => $presenter->groceryReturnLine($line),
                $lines,
            ),
        ]);
    }

    /** Every lot of the return in one idempotent operation; see GroceryReturnService. */
    #[Route('/confirm', methods: ['POST'])]
    public function confirm(
        Request $request,
        RequestContext $context,
        GroceryReturnService $service,
    ): JsonResponse {
        $payload = JsonPayload::fromRequest($request);

        $response = $service->confirm(
            $context->household(),
            $context->requireMember(),
            $payload->requiredString('operationKey'),
            $this->requestHash($payload),
            $payload->objectList('lines'),
            $payload->objectList('ignored'),
        );

        return $this->json($response, Response::HTTP_CREATED);
    }

    /**
     * Reads one receipt (one to three photos, multipart `images[]`) into lines
     * of the basket, filled in like any other line. Nothing is written: the
     * lines go to the phone's basket and come back through `confirm`, with
     * the printed label of each so that the household's answer is learnt.
     */
    #[Route('/receipt', methods: ['POST'])]
    public function receipt(
        Request $request,
        RequestContext $context,
        ReceiptReader $reader,
        ApiPresenter $presenter,
    ): JsonResponse {
        $raw = $request->files->get('images') ?? $request->files->get('image');
        /** @var list<UploadedFile|null> $files */
        $files = is_array($raw) ? array_values($raw) : (null !== $raw ? [$raw] : []);

        if ([] === $files) {
            throw new BadRequestHttpException('Joignez au moins une photo du ticket (champ « images »).');
        }

        if (count($files) > ReceiptReader::MAX_IMAGES) {
            throw new BadRequestHttpException(sprintf('Au plus %d photos par ticket.', ReceiptReader::MAX_IMAGES));
        }

        $images = [];
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                throw new BadRequestHttpException('Une des photos n\'a pas pu être reçue. Reprenez-la.');
            }

            // Read from the bytes, never from the name the phone gave the file:
            // `finfo` is already there, the Mime component is not.
            $mediaType = (string) (new \finfo(\FILEINFO_MIME_TYPE))->file($file->getPathname());
            if (!in_array($mediaType, self::RECEIPT_MEDIA_TYPES, true)) {
                throw new BadRequestHttpException('Les photos doivent être des images JPEG, PNG ou WebP.');
            }

            if ($file->getSize() > self::RECEIPT_MAX_BYTES) {
                throw new BadRequestHttpException('Une photo dépasse 8 Mo : réduisez sa taille avant de l\'envoyer.');
            }

            $images[] = [
                'mediaType' => $mediaType,
                'data' => base64_encode((string) file_get_contents($file->getPathname())),
            ];
        }

        $reading = $reader->read($context->household(), $images);

        return $this->json([
            'store' => $reading->store,
            'receiptDate' => $reading->receiptDate?->format('Y-m-d'),
            'total' => $reading->total,
            'lines' => array_map(
                static fn (array $entry): array => $presenter->groceryReturnLine($entry['line']) + [
                    'receipt' => [
                        'store' => $reading->store,
                        'rawLabel' => $entry['rawLabel'],
                        'lineTotal' => $entry['lineTotal'],
                    ],
                ],
                $reading->lines,
            ),
            'ignored' => array_map(
                static fn (array $entry): array => $entry + ['store' => $reading->store],
                $reading->ignored,
            ),
        ]);
    }

    private const RECEIPT_MEDIA_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    private const RECEIPT_MAX_BYTES = 8_000_000;

    /** The request's fingerprint, minus the transport details — as the stock routes compute it. */
    private function requestHash(JsonPayload $payload): string
    {
        $data = $payload->raw();
        unset($data['operationKey']);

        return hash('sha256', json_encode($data, \JSON_THROW_ON_ERROR));
    }
}
