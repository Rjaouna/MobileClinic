<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CustomerCheckIn;
use App\Entity\GeneralSetting;
use App\Entity\User;
use App\Repository\CustomerCheckInRepository;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class StoreCheckInManager
{
    public const COOLDOWN_SECONDS = 60;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CustomerCheckInRepository $checkInRepository,
        private readonly GeneralSettingManager $generalSettingManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getSetting(): GeneralSetting
    {
        $setting = $this->generalSettingManager->getSetting();

        if ($setting->getStoreCheckInToken() === null) {
            $setting->setStoreCheckInToken($this->newToken());
            $this->entityManager->flush();
        }

        return $setting;
    }

    public function updateSettings(bool $enabled, int $expirationMinutes): GeneralSetting
    {
        if ($expirationMinutes < 5 || $expirationMinutes > 60) {
            throw new \InvalidArgumentException('La présence doit rester valable entre 5 et 60 minutes.');
        }

        $setting = $this->getSetting()
            ->setStoreCheckInEnabled($enabled)
            ->setStoreCheckInExpirationMinutes($expirationMinutes);
        $this->entityManager->flush();

        return $setting;
    }

    public function regenerateToken(): GeneralSetting
    {
        $setting = $this->getSetting()->setStoreCheckInToken($this->newToken());
        $this->entityManager->flush();

        return $setting;
    }

    public function isValidToken(string $token): bool
    {
        $setting = $this->getSetting();
        $expected = $setting->getStoreCheckInToken();

        return $setting->isStoreCheckInEnabled()
            && $expected !== null
            && strlen($token) === strlen($expected)
            && hash_equals($expected, $token);
    }

    /** @return array{url: string, data_uri: string} */
    public function buildQrCode(): array
    {
        $setting = $this->getSetting();
        $url = $this->checkInUrl($setting);

        return [
            'url' => $url,
            'data_uri' => $this->renderQrCode($url, true),
        ];
    }

    public function buildQrCodeSvg(): string
    {
        return $this->renderQrCode($this->checkInUrl($this->getSetting()), false);
    }

    /** @return array{check_in: CustomerCheckIn, created: bool, throttled: bool} */
    public function checkIn(User $customer, ?\DateTimeImmutable $now = null): array
    {
        if (in_array('ROLE_ADMIN', $customer->getRoles(), true)) {
            throw new \InvalidArgumentException('Un compte administrateur ne peut pas signaler une présence client.');
        }

        $now ??= new \DateTimeImmutable();

        return $this->entityManager->wrapInTransaction(function () use ($customer, $now): array {
            $checkIn = $this->checkInRepository->findUnresolvedForCustomer($customer);
            $created = !$checkIn instanceof CustomerCheckIn;
            $checkIn ??= (new CustomerCheckIn())->setCustomer($customer);
            $elapsed = $now->getTimestamp() - $checkIn->getLastScannedAt()->getTimestamp();

            if (!$created && $elapsed >= 0 && $elapsed < self::COOLDOWN_SECONDS) {
                return ['check_in' => $checkIn, 'created' => false, 'throttled' => true];
            }

            $expirationMinutes = $this->getSetting()->getStoreCheckInExpirationMinutes();
            $checkIn->registerScan($now, $now->modify(sprintf('+%d minutes', $expirationMinutes)));

            if ($created) {
                $this->entityManager->persist($checkIn);
            }

            $this->entityManager->flush();

            return ['check_in' => $checkIn, 'created' => $created, 'throttled' => false];
        });
    }

    public function getUnresolvedForCustomer(User $customer): ?CustomerCheckIn
    {
        return $this->checkInRepository->findUnresolvedForCustomer($customer);
    }

    public function resolve(CustomerCheckIn $checkIn, User $administrator, string $resolution): void
    {
        $checkIn->resolve($administrator, $resolution);
        $this->entityManager->flush();
    }

    /**
     * @return array{
     *     total: int,
     *     toast_keys: list<string>,
     *     items: list<array<string, mixed>>
     * }
     */
    public function buildAdminView(int $limit = 8): array
    {
        $now = new \DateTimeImmutable();
        $checkIns = $this->checkInRepository->findUnresolved($limit);
        $items = array_map(fn (CustomerCheckIn $checkIn): array => $this->buildAdminItem($checkIn, $now), $checkIns);

        return [
            'total' => $this->checkInRepository->countUnresolved(),
            'toast_keys' => array_column($items, 'toast_key'),
            'items' => $items,
        ];
    }

    /** @return array<string, mixed> */
    private function buildAdminItem(CustomerCheckIn $checkIn, \DateTimeImmutable $now): array
    {
        $customer = $checkIn->getCustomer();
        $isExpired = $checkIn->isExpired($now);
        $elapsedSeconds = max(0, $now->getTimestamp() - $checkIn->getLastScannedAt()->getTimestamp());

        return [
            'id' => $checkIn->getId(),
            'toast_key' => sprintf('STORE_CHECK_IN:%d:%d', $checkIn->getId(), $checkIn->getLastScannedAt()->getTimestamp()),
            'check_in' => $checkIn,
            'customer' => $customer,
            'customer_name' => $customer?->getDisplayName() ?? 'Client',
            'email' => $customer?->getEmail() ?? '',
            'phone' => $customer?->getPhone(),
            'is_expired' => $isExpired,
            'level_variant' => $isExpired ? 'urgent' : 'arrival',
            'type_label' => $isExpired ? 'Présence à vérifier' : 'Client arrivé en magasin',
            'title' => $isExpired
                ? sprintf('Présence de %s non traitée', $customer?->getDisplayName() ?? 'ce client')
                : sprintf('%s attend au comptoir', $customer?->getDisplayName() ?? 'Un client'),
            'message' => $isExpired
                ? 'Le délai de présence est dépassé, mais la notification reste affichée jusqu’à votre décision.'
                : 'Le client a scanné le QR du magasin et souhaite accéder à sa cagnotte fidélité.',
            'elapsed_label' => $this->elapsedLabel($elapsedSeconds),
            'expires_label' => $checkIn->getExpiresAt()->format('H:i'),
        ];
    }

    private function elapsedLabel(int $seconds): string
    {
        if ($seconds < 60) {
            return 'À l’instant';
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return sprintf('Il y a %d min', $minutes);
        }

        $hours = intdiv($minutes, 60);

        return sprintf('Il y a %d h', $hours);
    }

    private function checkInUrl(GeneralSetting $setting): string
    {
        $token = $setting->getStoreCheckInToken();

        if ($token === null) {
            throw new \LogicException('Le QR code de présence n’est pas initialisé.');
        }

        return $this->urlGenerator->generate(
            'app_user_store_check_in',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    private function renderQrCode(string $url, bool $base64): string
    {
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => $base64,
            'svgAddXmlHeader' => !$base64,
            'connectPaths' => true,
            'eccLevel' => EccLevel::M,
            'scale' => 8,
            'addQuietzone' => true,
            'quietzoneSize' => 4,
        ]);
        $result = (new QRCode($options))->render($url);

        if (!is_string($result)) {
            throw new \RuntimeException('Le QR code magasin n’a pas pu être généré.');
        }

        return $result;
    }

    private function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
