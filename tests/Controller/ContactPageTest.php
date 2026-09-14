<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\DatabaseResetTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ContactPageTest extends WebTestCase
{
    use DatabaseResetTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->resetDoctrineSchema();
    }

    #[Test]
    public function contactPageUsesTheCentralStoreInformationAndGoogleLink(): void
    {
        $client = $this->client;
        $crawler = $client->request('GET', '/contact');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Votre atelier mobile');
        self::assertStringContainsString('18 Rue du Sec Arembault, 59800 Lille', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('03 20 50 71 03', (string) $client->getResponse()->getContent());
        self::assertSelectorCount(7, '.contact-hours__day');
        self::assertSelectorExists('a[href="https://share.google/mUlac1hXzMCLRVTds"][target="_blank"]');
        self::assertSame(
            ['Réparation', 'Fidélité', 'Actualités', 'Recrutement', 'Contact', 'Promotions'],
            $crawler->filter('.site-nav a')->each(static fn ($node): string => trim($node->text())),
        );
        self::assertSelectorExists('.site-nav__link.is-active[href="/contact"]');
    }
}
