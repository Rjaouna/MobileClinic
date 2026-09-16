<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Appointment;
use App\Entity\GeneralSetting;
use App\Entity\LoyaltyTransaction;
use App\Entity\ProductReservation;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class CustomerNotificationMailer
{
    private const TIMEZONE = 'Europe/Paris';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly GeneralSettingManager $generalSettingManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(bool:NOTIFICATION_ENABLED)%')]
        private readonly bool $enabled,
        #[Autowire('%env(NOTIFICATION_FROM_EMAIL)%')]
        private readonly string $fromEmail,
        #[Autowire('%env(NOTIFICATION_ADMIN_EMAIL)%')]
        private readonly string $adminEmail,
        #[Autowire('%env(NOTIFICATION_SITE_URL)%')]
        private readonly string $configuredSiteUrl,
    ) {
    }

    public function sendAccountCreated(User $customer, ?string $temporaryPassword = null, bool $createdByAdmin = false): bool
    {
        $setting = $this->generalSettingManager->getSetting();
        $customerContext = [
            'customer' => $customer,
            'temporary_password' => $temporaryPassword,
            'created_by_admin' => $createdByAdmin,
            'login_url' => $this->routeUrl($setting, 'app_login'),
            'profile_url' => $this->routeUrl($setting, 'app_user_profile_index'),
        ];

        return $this->sendToCustomer(
            $customer,
            'Votre espace client Mobile Clinic est prêt',
            'emails/account_created.html.twig',
            'emails/account_created.txt.twig',
            $customerContext,
            $setting,
        );
    }

    public function sendAppointmentCreated(Appointment $appointment): void
    {
        $this->sendAppointment($appointment, 'created');
    }

    public function sendAppointmentChanged(Appointment $appointment, string $event = 'status_changed'): void
    {
        $this->sendAppointment($appointment, $event);
    }

    public function sendLoyaltyMovement(LoyaltyTransaction $transaction): void
    {
        $customer = $transaction->getAccount()?->getCustomer();

        if (!$customer instanceof User) {
            return;
        }

        $setting = $this->generalSettingManager->getSetting();
        $amountLabel = $this->money($transaction->getAmountCents());
        $context = [
            'customer' => $customer,
            'transaction' => $transaction,
            'amount_label' => $amountLabel,
            'balance_label' => $this->money($transaction->getAccount()?->getAvailableBalanceCents() ?? 0, false),
            'loyalty_url' => $this->routeUrl($setting, 'app_user_loyalty_index'),
        ];

        $this->sendToCustomer(
            $customer,
            sprintf('Mouvement fidélité : %s', $amountLabel),
            'emails/loyalty_movement.html.twig',
            'emails/loyalty_movement.txt.twig',
            $context,
            $setting,
        );
    }

    public function sendProductReservationChanged(ProductReservation $reservation, string $event): void
    {
        $customer = $reservation->getCustomer();

        if (!$customer instanceof User) {
            return;
        }

        $setting = $this->generalSettingManager->getSetting();
        $eventLabels = [
            'created' => 'Votre réservation boutique est enregistrée',
            'confirmed' => 'Votre réservation est gardée jusqu’à votre passage',
            'sold' => 'Votre retrait en magasin est confirmé',
            'cancelled_by_customer' => 'Votre réservation boutique est annulée',
            'cancelled_by_admin' => 'Votre réservation boutique a été annulée',
            'expired' => 'Votre réservation boutique a expiré',
        ];
        $title = $eventLabels[$event] ?? 'Votre réservation boutique a été mise à jour';
        $context = [
            'customer' => $customer,
            'reservation' => $reservation,
            'event' => $event,
            'title' => $title,
            'total_label' => $this->money($reservation->getTotalCents(), false),
            'loyalty_used_label' => $this->money($reservation->getLoyaltyUsedCents(), false),
            'loyalty_refunded_label' => $this->money($reservation->getLoyaltyRefundedCents(), false),
            'payable_label' => $this->money($reservation->getPayableCents(), false),
            'expires_label' => $this->dateTime($reservation->getExpiresAt()),
            'reservation_url' => $this->routeUrl($setting, 'app_user_product_reservation_index'),
        ];

        $this->sendToCustomer(
            $customer,
            $title,
            'emails/product_reservation.html.twig',
            'emails/product_reservation.txt.twig',
            $context,
            $setting,
        );
    }

    public function sendTestNotification(?string $recipient = null): bool
    {
        $setting = $this->generalSettingManager->getSetting();

        return $this->send(
            trim((string) ($recipient ?: $this->adminEmail)),
            'Équipe Mobile Clinic',
            'Test des notifications Mobile Clinic',
            'emails/test.html.twig',
            'emails/test.txt.twig',
            ['is_admin_copy' => false],
            $setting,
        );
    }

    private function sendAppointment(Appointment $appointment, string $event): void
    {
        $customer = $appointment->getCustomer();

        if (!$customer instanceof User) {
            return;
        }

        $setting = $this->generalSettingManager->getSetting();
        $eventTitles = [
            'created' => 'Votre rendez-vous est bien enregistré',
            'rescheduled' => 'Votre rendez-vous a été déplacé',
            'status_changed' => 'Le statut de votre rendez-vous a changé',
        ];
        $title = $eventTitles[$event] ?? $eventTitles['status_changed'];
        $manageUrl = $appointment->getId() !== null
            ? $this->routeUrl($setting, 'app_user_reservation_show', ['id' => $appointment->getId()])
            : $this->routeUrl($setting, 'app_user_reservation_index');
        $context = [
            'customer' => $customer,
            'appointment' => $appointment,
            'event' => $event,
            'title' => $title,
            'scheduled_label' => $this->dateTime($appointment->getScheduledAt()),
            'manage_url' => $manageUrl,
            'cancel_url' => $appointment->getId() !== null
                ? $this->routeUrl($setting, 'app_user_reservation_show', [
                    'id' => $appointment->getId(),
                    'modal' => 'reservation-cancel-modal',
                ])
                : null,
            'reschedule_url' => $appointment->getId() !== null
                ? $this->routeUrl($setting, 'app_user_reservation_show', [
                    'id' => $appointment->getId(),
                    'modal' => 'reservation-reschedule-modal',
                ])
                : null,
        ];

        $this->sendToCustomer(
            $customer,
            sprintf('%s · %s', $title, $appointment->getStatusLabel()),
            'emails/appointment.html.twig',
            'emails/appointment.txt.twig',
            $context,
            $setting,
        );
    }

    /** @param array<string, mixed> $context */
    private function sendToCustomer(
        User $customer,
        string $subject,
        string $htmlTemplate,
        string $textTemplate,
        array $context,
        GeneralSetting $setting,
    ): bool {
        return $this->send(
            $customer->getEmail(),
            $customer->getDisplayName(),
            $subject,
            $htmlTemplate,
            $textTemplate,
            $context + ['is_admin_copy' => false],
            $setting,
        );
    }

    /** @param array<string, mixed> $context */
    private function send(
        string $recipientEmail,
        string $recipientName,
        string $subject,
        string $htmlTemplate,
        string $textTemplate,
        array $context,
        GeneralSetting $setting,
    ): bool {
        if (!$this->enabled) {
            return true;
        }

        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) || !filter_var($this->fromEmail, FILTER_VALIDATE_EMAIL)) {
            $this->logger->error('Notification e-mail ignorée : adresse invalide.', [
                'recipient' => $recipientEmail,
                'subject' => $subject,
            ]);

            return false;
        }

        $brandName = trim((string) ($setting->getLegalProfile()['trade_name'] ?? '')) ?: 'Mobile Clinic';
        $email = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, $brandName))
            ->to(new Address($recipientEmail, $recipientName))
            ->subject($subject)
            ->htmlTemplate($htmlTemplate)
            ->textTemplate($textTemplate);

        $email->context($context + [
            'setting' => $setting,
            'brand_name' => $brandName,
            'site_url' => $this->siteUrl($setting),
            'logo_url' => $this->logoUrl($setting),
        ]);

        try {
            $this->mailer->send($email);

            $this->logger->info('Notification e-mail remise au serveur SMTP.', [
                'recipient' => $recipientEmail,
                'subject' => $subject,
            ]);

            return true;
        } catch (\Throwable $exception) {
            $this->logger->error('Échec de remise d’une notification e-mail.', [
                'recipient' => $recipientEmail,
                'subject' => $subject,
                'exception' => $exception,
            ]);

            return false;
        }
    }

    private function logoUrl(GeneralSetting $setting): ?string
    {
        $logoPath = trim((string) $setting->getSiteLogoPath());

        if ($logoPath === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $logoPath) === 1) {
            return $logoPath;
        }

        return rtrim($this->siteUrl($setting), '/').'/'.ltrim(str_replace('\\', '/', $logoPath), '/');
    }

    /** @param array<string, scalar|null> $parameters */
    private function routeUrl(GeneralSetting $setting, string $route, array $parameters = []): string
    {
        $path = $this->urlGenerator->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_PATH);

        return rtrim($this->siteUrl($setting), '/').$path;
    }

    private function siteUrl(GeneralSetting $setting): string
    {
        $legalSiteUrl = trim((string) ($setting->getLegalProfile()['site_url'] ?? ''));
        $siteUrl = $legalSiteUrl !== '' ? $legalSiteUrl : trim($this->configuredSiteUrl);

        return rtrim($siteUrl !== '' ? $siteUrl : 'http://localhost', '/');
    }

    private function dateTime(?\DateTimeImmutable $date): string
    {
        if (!$date instanceof \DateTimeImmutable) {
            return 'Non renseigné';
        }

        return $date
            ->setTimezone(new \DateTimeZone(self::TIMEZONE))
            ->format('d/m/Y à H:i');
    }

    private function money(int $amountCents, bool $signed = true): string
    {
        $sign = $signed ? ($amountCents > 0 ? '+' : ($amountCents < 0 ? '−' : '')) : '';

        return $sign.number_format(abs($amountCents) / 100, 2, ',', ' ').' €';
    }
}
