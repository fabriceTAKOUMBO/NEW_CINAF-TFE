<?php

namespace App\Tests\Command;

use App\Command\StripeSyncPlansCommand;
use App\Entity\SubscriptionPlan;
use App\Repository\SubscriptionPlanRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests unitaires pour la commande `app:stripe:sync-plans`.
 *
 * Choix de l'approche : on n'utilise PAS KernelTestCase ni de DB de test.
 *
 * Raisons :
 *  - La commande est très petite (1 garde-fou env + 1 garde-fou DB + 1 mutation + 1 flush).
 *  - Toute sa logique tient dans `execute()` — pas de service tiers à câbler.
 *  - Mocker {@see SubscriptionPlanRepository} et {@see EntityManagerInterface} suffit
 *    pour exercer les 3 chemins (env vide, plan manquant, succès) avec une exécution
 *    < 100ms et sans dépendance à la DB ni au reset DB.
 *  - Un test KernelTestCase forcerait à reset la DB de test à chaque run, ralentissant
 *    inutilement la suite pour aucune valeur de couverture supplémentaire.
 *
 * Pour l'intégration réelle (env -> services.yaml -> command), la vérification
 * manuelle `php bin/console app:stripe:sync-plans` exécutée par Fabrice après merge
 * sert de smoke test bout-en-bout.
 *
 * Aucun appel à Stripe ni à la base : ce test peut tourner sans base de test.
 * Lancement : `php bin/phpunit tests/Command/StripeSyncPlansCommandTest.php`.
 */
class StripeSyncPlansCommandTest extends TestCase
{
    /**
     * Cas n°1 — `STRIPE_PRICE_MONTHLY` vide → exit 1 + message d'erreur lisible.
     */
    public function testCommandFailsWhenMonthlyPriceEnvIsEmpty(): void
    {
        $repo = $this->createMock(SubscriptionPlanRepository::class);
        // Le repo ne doit JAMAIS être interrogé : on s'arrête au garde-fou env.
        $repo->expects($this->never())->method('findOneBy');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $command = new StripeSyncPlansCommand(
            $repo,
            $em,
            monthlyPriceId: '',                         // <-- vide
            yearlyPriceId: 'price_test_yearly',
        );

        $tester = $this->buildTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        // Mot-clé identifiable côté ops (cf. message dans la commande).
        $this->assertStringContainsString('manquante', $tester->getDisplay());
        $this->assertStringContainsString('STRIPE_PRICE_MONTHLY', $tester->getDisplay());
    }

    /**
     * Cas n°2 — DB vide → exit 2 + message "plan introuvable".
     *
     * On distingue ce code de retour (2) du précédent (1) pour permettre
     * un diagnostic shell granulaire (`if [ $? -eq 2 ]; then ...`).
     */
    public function testCommandFailsWhenPlansAreMissingInDb(): void
    {
        // Stub plutôt que mock : on ne vérifie pas d'interaction, on configure juste le retour.
        $repo = $this->createStub(SubscriptionPlanRepository::class);
        // Repository renvoie null pour les deux plans → simulation DB vide.
        $repo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $command = new StripeSyncPlansCommand(
            $repo,
            $em,
            monthlyPriceId: 'price_test_monthly',
            yearlyPriceId: 'price_test_yearly',
        );

        $tester = $this->buildTester($command);
        $exitCode = $tester->execute([]);

        // 2 = EXIT_PLAN_NOT_FOUND (constante privée, donc on teste la valeur numérique).
        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('introuvable', $tester->getDisplay());
        $this->assertStringContainsString('Mensuel', $tester->getDisplay());
        $this->assertStringContainsString('Annuel', $tester->getDisplay());
    }

    /**
     * Cas n°3 — Les deux plans existent → setters appelés, flush appelé, exit 0.
     *
     * Vérifie aussi qu'on n'a pas inversé monthly ↔ yearly (bug classique).
     */
    public function testCommandUpdatesBothPlansSuccessfully(): void
    {
        // Plans pré-existants avec un ancien ID (simule la situation "compte LIVE
        // remplacé par TEST" — c'est le use case réel de la commande).
        $monthly = new SubscriptionPlan();
        $monthly->setName('Mensuel');
        $monthly->setPriceCents(999);
        $monthly->setStripePriceId('price_OLD_monthly');

        $yearly = new SubscriptionPlan();
        $yearly->setName('Annuel');
        $yearly->setPriceCents(9900);
        $yearly->setIntervalUnit(SubscriptionPlan::INTERVAL_YEAR);
        $yearly->setStripePriceId('price_OLD_yearly');

        // Stub : on configure le retour selon le critère mais on ne contraint pas le nombre d'appels.
        $repo = $this->createStub(SubscriptionPlanRepository::class);
        // On utilise willReturnCallback pour distinguer les deux appels par leur critère.
        $repo->method('findOneBy')->willReturnCallback(
            function (array $criteria) use ($monthly, $yearly): ?SubscriptionPlan {
                return match ($criteria['name'] ?? null) {
                    'Mensuel' => $monthly,
                    'Annuel' => $yearly,
                    default => null,
                };
            },
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $command = new StripeSyncPlansCommand(
            $repo,
            $em,
            monthlyPriceId: 'price_NEW_monthly',
            yearlyPriceId: 'price_NEW_yearly',
        );

        $tester = $this->buildTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        // Vérifie côté entités que les nouveaux IDs ont bien été appliqués
        // — c'est le contrat principal de la commande.
        $this->assertSame('price_NEW_monthly', $monthly->getStripePriceId());
        $this->assertSame('price_NEW_yearly', $yearly->getStripePriceId());

        // Vérifie le récap visuel : on doit voir l'ancien ID dans "Avant" et le nouveau dans "Après".
        $display = $tester->getDisplay();
        $this->assertStringContainsString('price_OLD_monthly', $display, 'La colonne "Avant" doit afficher l\'ancien ID mensuel.');
        $this->assertStringContainsString('price_NEW_monthly', $display, 'La colonne "Après" doit afficher le nouvel ID mensuel.');
        $this->assertStringContainsString('2 plans synchronisés', $display);
    }

    /**
     * Wrap la commande dans une Application minimaliste et renvoie un CommandTester.
     */
    private function buildTester(StripeSyncPlansCommand $command): CommandTester
    {
        $application = new Application();
        $application->add($command);

        return new CommandTester($application->find('app:stripe:sync-plans'));
    }
}
