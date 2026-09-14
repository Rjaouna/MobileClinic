<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Service\GeneralSettingManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ContactController extends AbstractController
{
    private const DAY_LABELS = [
        'monday' => 'Lundi',
        'tuesday' => 'Mardi',
        'wednesday' => 'Mercredi',
        'thursday' => 'Jeudi',
        'friday' => 'Vendredi',
        'saturday' => 'Samedi',
        'sunday' => 'Dimanche',
    ];

    public function __construct(private readonly GeneralSettingManager $generalSettingManager)
    {
    }

    #[Route('/contact', name: 'app_public_contact', methods: ['GET'])]
    public function index(): Response
    {
        $setting = $this->generalSettingManager->getSetting();
        $dayKeys = array_keys(self::DAY_LABELS);
        $currentDay = $dayKeys[(int) (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('N') - 1];

        return $this->render('public/contact/index.html.twig', [
            'setting' => $setting,
            'legal' => $setting->getLegalProfile(),
            'day_labels' => self::DAY_LABELS,
            'current_day' => $currentDay,
        ]);
    }
}
