<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\RecruitmentApplication;
use App\Entity\RecruitmentPosition;
use App\Entity\User;
use App\Repository\RecruitmentApplicationRepository;
use App\Repository\RecruitmentPositionRepository;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RecruitmentFlowTest extends WebTestCase
{
    use DatabaseResetTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->entityManager = $this->resetDoctrineSchema();
    }

    #[Test]
    public function adminCanCreateAnAdministrablePosition(): void
    {
        $admin = $this->user('recruitment-admin@symaclinic.fr', '+33605000801', ['ROLE_ADMIN']);
        $this->entityManager->flush();
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/recrutement');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('button[data-modal-open="recruitment-position-add"]');

        $token = $crawler->filter('form[action="/admin/recrutement/postes"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/recrutement/postes', [
            '_token' => $token,
            'category' => RecruitmentPosition::CATEGORY_ATELIER,
            'title' => 'Technicien microsoudure',
            'rhythm' => 'Temps plein · Selon profil',
            'description' => 'Diagnostiquez les cartes électroniques et réalisez des réparations précises en atelier.',
            'highlights' => ['Microsoudure', 'Diagnostic avancé', 'Contrôle qualité'],
            'is_active' => '1',
        ]);

        self::assertResponseRedirects('/admin/recrutement#recruitment-positions-title');
        $position = static::getContainer()->get(RecruitmentPositionRepository::class)->findOneBy(['title' => 'Technicien microsoudure']);
        self::assertInstanceOf(RecruitmentPosition::class, $position);
        self::assertSame(['Microsoudure', 'Diagnostic avancé', 'Contrôle qualité'], $position->getHighlights());

        $this->client->request('GET', '/recrutement');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Technicien microsoudure', $this->responseContent());
        self::assertStringContainsString('Diagnostic avancé', $this->responseContent());
    }

    #[Test]
    public function connectedCustomerIsPrefilledAndApplicationKeepsThePositionLink(): void
    {
        $customer = $this->user('candidat@symaclinic.fr', '+33605000802');
        $customer
            ->setFirstName('Camille')
            ->setLastName('Martin');
        $position = (new RecruitmentPosition())
            ->setCategory(RecruitmentPosition::CATEGORY_BOUTIQUE)
            ->setTitle('Conseiller service client')
            ->setRhythm('Temps plein')
            ->setDescription('Accueillez les clients et accompagnez chaque demande avec précision et pédagogie.')
            ->setHighlights(['Accueil client', 'Suivi des demandes']);
        $this->entityManager->persist($position);
        $this->entityManager->flush();

        $this->client->loginUser($customer);
        $crawler = $this->client->request('GET', sprintf('/recrutement?position=%d', $position->getId()));
        self::assertResponseIsSuccessful();
        self::assertSame('Camille', $crawler->filter('input[name="first_name"]')->attr('value'));
        self::assertSame('Martin', $crawler->filter('input[name="last_name"]')->attr('value'));
        self::assertSame('candidat@symaclinic.fr', $crawler->filter('input[name="email"]')->attr('value'));
        self::assertSame('+33605000802', $crawler->filter('input[name="phone"]')->attr('value'));
        self::assertSame((string) $position->getId(), $crawler->filter('select[name="position_id"] option[selected]')->attr('value'));

        $token = $crawler->filter('form[action="/recrutement/postuler"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/recrutement/postuler', [
            '_token' => $token,
            'first_name' => 'Camille',
            'last_name' => 'Martin',
            'email' => 'candidat@symaclinic.fr',
            'phone' => '+33605000802',
            'position_id' => (string) $position->getId(),
            'availability' => 'Immédiate',
            'experience_level' => '1 à 2 ans',
            'message' => 'Je souhaite mettre mon expérience du service client au service de votre boutique.',
        ]);

        self::assertResponseRedirects('/recrutement');
        $applications = static::getContainer()->get(RecruitmentApplicationRepository::class)->findAll();
        self::assertCount(1, $applications);
        self::assertInstanceOf(RecruitmentApplication::class, $applications[0]);
        self::assertSame($position->getId(), $applications[0]->getPosition()?->getId());
        self::assertSame('Conseiller service client', $applications[0]->getDesiredPosition());

        $admin = $this->user('recruitment-review@symaclinic.fr', '+33605000803', ['ROLE_ADMIN']);
        $this->entityManager->flush();
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/recrutement');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists(sprintf('button[data-modal-open="recruitment-position-show-%d"]', $position->getId()));
        self::assertSelectorExists(sprintf(
            'dialog#recruitment-application-%d.modal--fullscreen.modal--recruitment-application > .modal__panel > .modal__body',
            $applications[0]->getId(),
        ));
        self::assertCount(0, $crawler->filter('dialog.modal:not(.modal--fullscreen)'));
        self::assertStringContainsString('Voir le poste', $this->responseContent());
    }

    /** @param list<string> $roles */
    private function user(string $email, string $phone, array $roles = ['ROLE_USER']): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setPhone($phone)
            ->setRoles($roles)
            ->setIsActive(true);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, $email));
        $this->entityManager->persist($user);

        return $user;
    }

    private function responseContent(): string
    {
        return $this->client->getResponse()->getContent() ?: '';
    }
}
