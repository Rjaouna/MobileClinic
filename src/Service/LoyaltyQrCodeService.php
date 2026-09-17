<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class LoyaltyQrCodeService
{
    public const VALIDITY_SECONDS = 300;

    public function __construct(
        private readonly UriSigner $uriSigner,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array{
     *     data_uri: string,
     *     scan_url: string,
     *     generated_at: \DateTimeImmutable,
     *     expires_at: \DateTimeImmutable,
     *     validity_seconds: int
     * }
     */
    public function createFor(User $customer, ?\DateTimeImmutable $generatedAt = null): array
    {
        $customerId = $customer->getId();

        if ($customerId === null) {
            throw new \LogicException('Le client doit être enregistré avant de générer son QR code.');
        }

        $generatedAt ??= new \DateTimeImmutable();
        $expiresAt = $generatedAt->modify(sprintf('+%d seconds', self::VALIDITY_SECONDS));
        $scanUrl = $this->urlGenerator->generate(
            'app_admin_loyalty_scan',
            ['id' => $customerId],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $signedUrl = $this->uriSigner->sign($scanUrl, $expiresAt);
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => true,
            'svgAddXmlHeader' => false,
            'connectPaths' => true,
            'eccLevel' => EccLevel::M,
            'scale' => 8,
            'addQuietzone' => true,
            'quietzoneSize' => 4,
        ]);
        $dataUri = (new QRCode($options))->render($signedUrl);

        if (!is_string($dataUri)) {
            throw new \RuntimeException('Le QR code fidélité n’a pas pu être généré.');
        }

        return [
            'data_uri' => $dataUri,
            'scan_url' => $signedUrl,
            'generated_at' => $generatedAt,
            'expires_at' => $expiresAt,
            'validity_seconds' => self::VALIDITY_SECONDS,
        ];
    }

    public function verify(Request $request): void
    {
        $this->uriSigner->verify($request);
    }
}
