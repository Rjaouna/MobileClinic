<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Appointment;
use App\Entity\AppointmentAvailability;
use App\Entity\AppointmentSetting;
use App\Repository\AppointmentAvailabilityRepository;
use App\Repository\AppointmentRepository;
use App\Repository\AppointmentSettingRepository;
use Doctrine\ORM\EntityManagerInterface;

final class AppointmentScheduler
{
    private const TIMEZONE = 'Europe/Paris';

    /** @var array<string, string> */
    private const DEVICE_CHOICES = [
        'iphone' => 'iPhone',
        'samsung' => 'Samsung Galaxy',
        'xiaomi' => 'Xiaomi',
        'other' => 'Autre modèle',
    ];

    /** @var array<string, string> */
    private const PROBLEM_CHOICES = [
        'screen' => 'Écran cassé',
        'battery' => 'Batterie faible',
        'connector' => 'Connecteur de charge',
        'diagnostic' => 'Diagnostic complet',
    ];

    /** @var array<int, string> */
    private const DAYS = [
        1 => 'lundi',
        2 => 'mardi',
        3 => 'mercredi',
        4 => 'jeudi',
        5 => 'vendredi',
        6 => 'samedi',
        7 => 'dimanche',
    ];

    /** @var array<int, string> */
    private const MONTHS = [
        1 => 'janvier',
        2 => 'février',
        3 => 'mars',
        4 => 'avril',
        5 => 'mai',
        6 => 'juin',
        7 => 'juillet',
        8 => 'août',
        9 => 'septembre',
        10 => 'octobre',
        11 => 'novembre',
        12 => 'décembre',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AppointmentRepository $appointmentRepository,
        private readonly AppointmentAvailabilityRepository $availabilityRepository,
        private readonly AppointmentSettingRepository $settingRepository,
        private readonly AppointmentReminderManager $appointmentReminderManager,
    ) {
    }

    /** @return list<array{value: string, label: string}> */
    public function getDeviceChoices(bool $withPlaceholder = true): array
    {
        return $this->buildChoices(self::DEVICE_CHOICES, $withPlaceholder ? 'Choisir un appareil' : null);
    }

    /** @return list<array{value: string, label: string}> */
    public function getProblemChoices(bool $withPlaceholder = true): array
    {
        return $this->buildChoices(self::PROBLEM_CHOICES, $withPlaceholder ? 'Choisir un problème' : null);
    }

    public function isValidDevice(string $device): bool
    {
        return isset(self::DEVICE_CHOICES[$device]);
    }

    public function isValidProblem(string $problem): bool
    {
        return isset(self::PROBLEM_CHOICES[$problem]);
    }

    public function getDeviceLabel(string $device): string
    {
        return self::DEVICE_CHOICES[$device] ?? $device;
    }

    public function getProblemLabel(string $problem): string
    {
        return self::PROBLEM_CHOICES[$problem] ?? $problem;
    }

    /** @return array<int, string> */
    public function getDays(): array
    {
        return AppointmentAvailability::DAYS;
    }

    public function getSetting(): AppointmentSetting
    {
        $setting = $this->settingRepository->findCurrent();

        if ($setting instanceof AppointmentSetting) {
            return $setting;
        }

        $setting = new AppointmentSetting();
        $this->entityManager->persist($setting);
        $this->entityManager->flush();

        return $setting;
    }

    /**
     * @return list<array{
     *     value: string,
     *     label: string,
     *     date_key: string,
     *     date_label: string,
     *     day_name: string,
     *     day_number: string,
     *     month_label: string,
     *     short_day_label: string,
     *     time_label: string,
     *     duration_minutes: int
     * }>
     */
    public function getBookableSlots(?Appointment $excludedAppointment = null): array
    {
        $setting = $this->getSetting();
        $availabilitiesByDay = $this->groupEnabledAvailabilitiesByDay();

        if ($availabilitiesByDay === []) {
            return [];
        }

        $now = new \DateTimeImmutable('now', $this->getTimezone());
        $today = new \DateTimeImmutable('today', $this->getTimezone());
        $windowEnd = $today->modify(sprintf('+%d days', $setting->getBookingWindowDays() + 1));
        $occupiedSlots = array_flip(array_map(
            static fn (\DateTimeImmutable $scheduledAt): string => $scheduledAt->format('Y-m-d H:i'),
            $this->appointmentRepository->findActiveScheduledAtBetween($now, $windowEnd, $excludedAppointment),
        ));
        $slots = [];

        for ($dayOffset = 0; $dayOffset <= $setting->getBookingWindowDays(); ++$dayOffset) {
            $date = $today->modify(sprintf('+%d days', $dayOffset));
            $dayOfWeek = (int) $date->format('N');

            foreach ($availabilitiesByDay[$dayOfWeek] ?? [] as $availability) {
                $durationMinutes = max(1, $availability->getSlotDurationMinutes());
                $cursor = $this->combineDateAndTime($date, $availability->getStartTime());
                $end = $this->combineDateAndTime($date, $availability->getEndTime());

                while ($cursor->modify(sprintf('+%d minutes', $durationMinutes)) <= $end) {
                    if ($cursor > $now && !isset($occupiedSlots[$cursor->format('Y-m-d H:i')])) {
                        $slots[] = [
                            'value' => $cursor->format('Y-m-d H:i'),
                            'label' => sprintf('%s à %s', $this->formatDateLabel($cursor), $cursor->format('H:i')),
                            'date_key' => $cursor->format('Y-m-d'),
                            'date_label' => $this->formatDateLabel($cursor),
                            'day_name' => self::DAYS[(int) $cursor->format('N')],
                            'day_number' => $cursor->format('d'),
                            'month_label' => self::MONTHS[(int) $cursor->format('n')],
                            'short_day_label' => $this->formatShortDateLabel($cursor),
                            'time_label' => $cursor->format('H:i'),
                            'duration_minutes' => $durationMinutes,
                        ];
                    }

                    $cursor = $cursor->modify(sprintf('+%d minutes', $durationMinutes));
                }
            }
        }

        return $slots;
    }

    /**
     * @return list<array{
     *     date_key: string,
     *     label: string,
     *     day_name: string,
     *     day_number: string,
     *     month_label: string,
     *     short_day_label: string,
     *     slot_count: int,
     *     slots: list<array{
     *         value: string,
     *         label: string,
     *         date_key: string,
     *         date_label: string,
     *         day_name: string,
     *         day_number: string,
     *         month_label: string,
     *         short_day_label: string,
     *         time_label: string,
     *         duration_minutes: int
     *     }>
     * }>
     */
    public function getBookableSlotDays(?Appointment $excludedAppointment = null): array
    {
        $days = [];

        foreach ($this->getBookableSlots($excludedAppointment) as $slot) {
            $dateKey = $slot['date_key'];

            if (!isset($days[$dateKey])) {
                $days[$dateKey] = [
                    'date_key' => $dateKey,
                    'label' => $slot['date_label'],
                    'day_name' => $slot['day_name'],
                    'day_number' => $slot['day_number'],
                    'month_label' => $slot['month_label'],
                    'short_day_label' => $slot['short_day_label'],
                    'slot_count' => 0,
                    'slots' => [],
                ];
            }

            $days[$dateKey]['slots'][] = $slot;
            ++$days[$dateKey]['slot_count'];
        }

        return array_values($days);
    }

    /**
     * @return list<array{
     *     day_of_week: int,
     *     label: string,
     *     total_ranges: int,
     *     enabled_ranges: int,
     *     is_visible: bool,
     *     schedule_label: string
     * }>
     */
    public function getAvailabilityWeekOverview(): array
    {
        $rangesByDay = [];

        foreach ($this->availabilityRepository->findAllOrdered() as $availability) {
            $rangesByDay[$availability->getDayOfWeek()][] = $availability;
        }

        $overview = [];

        foreach ($this->getDays() as $dayOfWeek => $label) {
            $ranges = $rangesByDay[$dayOfWeek] ?? [];
            $enabledRanges = array_values(array_filter(
                $ranges,
                static fn (AppointmentAvailability $availability): bool => $availability->isEnabled(),
            ));

            $overview[] = [
                'day_of_week' => $dayOfWeek,
                'label' => $label,
                'total_ranges' => count($ranges),
                'enabled_ranges' => count($enabledRanges),
                'is_visible' => $enabledRanges !== [],
                'schedule_label' => $this->formatAvailabilitySummary($ranges, $enabledRanges),
            ];
        }

        return $overview;
    }

    /**
     * @return array{
     *     day_of_week: int,
     *     label: string,
     *     total_ranges: int,
     *     enabled_ranges: int,
     *     is_visible: bool,
     *     schedule_label: string
     * }
     */
    public function getAvailabilityDayOverview(int $dayOfWeek): array
    {
        foreach ($this->getAvailabilityWeekOverview() as $day) {
            if ($day['day_of_week'] === $dayOfWeek) {
                return $day;
            }
        }

        throw new \InvalidArgumentException('Le jour sélectionné est invalide.');
    }

    /** @return list<AppointmentAvailability> */
    public function getAvailabilitiesForDay(int $dayOfWeek): array
    {
        if (!isset($this->getDays()[$dayOfWeek])) {
            throw new \InvalidArgumentException('Le jour sélectionné est invalide.');
        }

        return $this->availabilityRepository->findByDayOrdered($dayOfWeek);
    }

    /**
     * @return array<int, list<array{start: string, end: string, label: string}>>
     */
    public function getAvailabilityRangesByDay(): array
    {
        $ranges = [];

        foreach ($this->getDays() as $dayOfWeek => $_label) {
            $ranges[$dayOfWeek] = [];
        }

        foreach ($this->availabilityRepository->findAllOrdered() as $availability) {
            $startTime = $availability->getStartTime();
            $endTime = $availability->getEndTime();

            if (!$startTime instanceof \DateTimeImmutable || !$endTime instanceof \DateTimeImmutable) {
                continue;
            }

            $ranges[$availability->getDayOfWeek()][] = [
                'start' => $startTime->format('H:i'),
                'end' => $endTime->format('H:i'),
                'label' => $this->formatAvailabilityRange($availability),
            ];
        }

        return $ranges;
    }

    public function findAvailabilityConflictMessage(int $dayOfWeek, \DateTimeImmutable $startTime, \DateTimeImmutable $endTime): ?string
    {
        foreach ($this->availabilityRepository->findByDayOrdered($dayOfWeek) as $availability) {
            $existingStart = $availability->getStartTime();
            $existingEnd = $availability->getEndTime();

            if (!$existingStart instanceof \DateTimeImmutable || !$existingEnd instanceof \DateTimeImmutable) {
                continue;
            }

            $start = $this->timeToMinutes($startTime);
            $end = $this->timeToMinutes($endTime);
            $rangeStart = $this->timeToMinutes($existingStart);
            $rangeEnd = $this->timeToMinutes($existingEnd);
            $rangeLabel = $this->formatAvailabilityRange($availability);

            if ($start === $rangeStart && $end === $rangeEnd) {
                return sprintf('Cette plage horaire existe déjà : %s.', $rangeLabel);
            }

            if ($start >= $rangeStart && $start < $rangeEnd) {
                return sprintf('L’heure de début est déjà couverte par la plage %s.', $rangeLabel);
            }

            if ($end > $rangeStart && $end <= $rangeEnd) {
                return sprintf('L’heure de fin est déjà couverte par la plage %s.', $rangeLabel);
            }

            if ($start <= $rangeStart && $end >= $rangeEnd) {
                return sprintf('Cette plage chevauche déjà la plage %s.', $rangeLabel);
            }
        }

        return null;
    }

    /**
     * @return array{
     *     value: string,
     *     label: string,
     *     date_key: string,
     *     date_label: string,
     *     day_name: string,
     *     day_number: string,
     *     month_label: string,
     *     short_day_label: string,
     *     time_label: string,
     *     duration_minutes: int
     * }|null
     */
    public function findBookableSlotData(string $value, ?Appointment $excludedAppointment = null): ?array
    {
        foreach ($this->getBookableSlots($excludedAppointment) as $slot) {
            if ($slot['value'] === $value) {
                return $slot;
            }
        }

        return null;
    }

    public function parseSlotValue(string $value): ?\DateTimeImmutable
    {
        $slot = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, $this->getTimezone());

        return $slot instanceof \DateTimeImmutable ? $slot : null;
    }

    public function syncExpiredAppointments(): int
    {
        $result = $this->appointmentReminderManager->refreshReminders();

        return $result['created'] + $result['updated'] + $result['resolved'];
    }

    public function formatAppointmentDate(\DateTimeImmutable $date): string
    {
        return sprintf('%s à %s', $this->formatDateLabel($date), $date->format('H:i'));
    }

    /**
     * @param list<AppointmentAvailability> $ranges
     * @param list<AppointmentAvailability> $enabledRanges
     */
    private function formatAvailabilitySummary(array $ranges, array $enabledRanges): string
    {
        if ($enabledRanges === []) {
            return $ranges === [] ? 'Aucune plage configurée' : 'Journée masquée';
        }

        return implode(', ', array_map(
            fn (AppointmentAvailability $availability): string => $this->formatAvailabilityRange($availability),
            $enabledRanges,
        ));
    }

    private function formatAvailabilityRange(AppointmentAvailability $availability): string
    {
        return sprintf(
            '%s - %s',
            $availability->getStartTime()?->format('H:i') ?? '--:--',
            $availability->getEndTime()?->format('H:i') ?? '--:--',
        );
    }

    /** @param array<string, string> $choices */
    private function buildChoices(array $choices, ?string $placeholder): array
    {
        $items = [];

        if ($placeholder !== null) {
            $items[] = ['value' => '', 'label' => $placeholder];
        }

        foreach ($choices as $value => $label) {
            $items[] = ['value' => $value, 'label' => $label];
        }

        return $items;
    }

    /** @return array<int, list<AppointmentAvailability>> */
    private function groupEnabledAvailabilitiesByDay(): array
    {
        $grouped = [];

        foreach ($this->availabilityRepository->findEnabledOrdered() as $availability) {
            if (!$availability->getStartTime() instanceof \DateTimeImmutable || !$availability->getEndTime() instanceof \DateTimeImmutable) {
                continue;
            }

            $grouped[$availability->getDayOfWeek()][] = $availability;
        }

        return $grouped;
    }

    private function combineDateAndTime(\DateTimeImmutable $date, ?\DateTimeImmutable $time): \DateTimeImmutable
    {
        if (!$time instanceof \DateTimeImmutable) {
            return $date;
        }

        return $date->setTime((int) $time->format('H'), (int) $time->format('i'));
    }

    private function formatDateLabel(\DateTimeImmutable $date): string
    {
        $dayName = self::DAYS[(int) $date->format('N')];
        $monthName = self::MONTHS[(int) $date->format('n')];

        return sprintf('%s %d %s', $dayName, (int) $date->format('j'), $monthName);
    }

    private function formatShortDateLabel(\DateTimeImmutable $date): string
    {
        $dayName = mb_substr(self::DAYS[(int) $date->format('N')], 0, 3);

        return sprintf('%s. %s/%s', $dayName, $date->format('d'), $date->format('m'));
    }

    private function timeToMinutes(\DateTimeImmutable $time): int
    {
        return ((int) $time->format('H') * 60) + (int) $time->format('i');
    }

    private function getTimezone(): \DateTimeZone
    {
        return new \DateTimeZone(self::TIMEZONE);
    }
}
