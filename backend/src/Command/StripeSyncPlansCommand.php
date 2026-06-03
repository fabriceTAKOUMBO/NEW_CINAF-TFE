<?php
namespace App\Command;

use App\Entity\SubscriptionPlan;
use App\Repository\SubscriptionPlanRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Commande de synchronisation des `stripePriceId` côté DB.
 *
 * Pourquoi cette commande ?
 *  - Le fixture {@see \App\DataFixtures\SubscriptionPlanFixtures} ne *remplace* pas
 *    un `stripePriceId` déjà non-null. Conséquence : quand on bascule d'un compte
 *    Stripe LIVE à un compte TEST (ou inversement), les anciens IDs persistent en DB.
 *  - Cette commande rafraîchit les deux plans canoniques ("Mensuel" / "Annuel") à
 *    partir des variables d'environnement `STRIPE_PRICE_MONTHLY` et
 *    `STRIPE_PRICE_YEARLY`, sans passer par la purge de la table.
 *
 * Codes de sortie :
 *  - 0 (SUCCESS)   — synchronisation OK
 *  - 1 (FAILURE)   — au moins une des deux variables d'env est vide
 *  - 2 (INVALID)   — un des deux plans est absent en DB (le repo a renvoyé null)
 *
 * Idempotente : si les IDs sont déjà bons, la commande re-flush silencieusement
 * (l'overhead est négligeable et ça simplifie le code — pas de "skip").
 */
#[AsCommand(
    name: 'app:stripe:sync-plans',
    description: "Synchronise les stripePriceId des SubscriptionPlan depuis les variables d'env.",
)]
class StripeSyncPlansCommand extends Command
{
    // Codes de sortie distincts pour faciliter le diagnostic en CI / scripts shell.
    private const EXIT_MISSING_ENV = 1;
    private const EXIT_PLAN_NOT_FOUND = 2;

    // Nom canonique des deux plans en base (cf. SubscriptionPlanFixtures).
    private const PLAN_MONTHLY_NAME = 'Mensuel';
    private const PLAN_YEARLY_NAME = 'Annuel';

    /**
     * @param string $monthlyPriceId Valeur de `STRIPE_PRICE_MONTHLY` (peut être vide en dev sans Stripe).
     * @param string $yearlyPriceId  Valeur de `STRIPE_PRICE_YEARLY` (peut être vide en dev sans Stripe).
     */
    public function __construct(
        private readonly SubscriptionPlanRepository $planRepository,
        private readonly EntityManagerInterface $em,
        #[Autowire(env: 'STRIPE_PRICE_MONTHLY')]
        private readonly string $monthlyPriceId,
        #[Autowire(env: 'STRIPE_PRICE_YEARLY')]
        private readonly string $yearlyPriceId,
    ) {
        parent::__construct();
    }

    /**
     * Synchronise les deux plans. Affiche un tableau "Avant / Après" pour audit visuel.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Synchronisation des stripePriceId (DB ← .env)');

        // Garde-fou n°1 : refuser d'écrire null/vide en DB. Une var manquante est
        // probablement un oubli de config ; on échoue tôt plutôt que d'effacer un ID valide.
        if ($this->monthlyPriceId === '' || $this->yearlyPriceId === '') {
            $io->error(sprintf(
                "Variable d'env manquante ou vide : STRIPE_PRICE_MONTHLY='%s', STRIPE_PRICE_YEARLY='%s'. "
                . "Renseigne les deux dans backend/.env.local avant de relancer.",
                $this->monthlyPriceId,
                $this->yearlyPriceId,
            ));
            return self::EXIT_MISSING_ENV;
        }

        // Garde-fou n°2 : on s'attend aux 2 plans canoniques créés par le fixture.
        // S'ils manquent, c'est qu'on n'a pas chargé les fixtures — ce n'est pas notre
        // rôle de les créer (séparation des responsabilités fixture vs sync).
        $monthly = $this->planRepository->findOneBy(['name' => self::PLAN_MONTHLY_NAME]);
        $yearly = $this->planRepository->findOneBy(['name' => self::PLAN_YEARLY_NAME]);

        if ($monthly === null || $yearly === null) {
            $missing = [];
            if ($monthly === null) {
                $missing[] = self::PLAN_MONTHLY_NAME;
            }
            if ($yearly === null) {
                $missing[] = self::PLAN_YEARLY_NAME;
            }
            $io->error(sprintf(
                'Plan introuvable en DB : %s. Lance `doctrine:fixtures:load` au préalable.',
                implode(', ', $missing),
            ));
            return self::EXIT_PLAN_NOT_FOUND;
        }

        // Récap "Avant" — capturé AVANT la mutation, sinon le tableau afficherait
        // la même valeur en colonnes "Avant" et "Après".
        $rows = [
            [
                self::PLAN_MONTHLY_NAME,
                $this->formatId($monthly->getStripePriceId()),
                $this->formatId($this->monthlyPriceId),
            ],
            [
                self::PLAN_YEARLY_NAME,
                $this->formatId($yearly->getStripePriceId()),
                $this->formatId($this->yearlyPriceId),
            ],
        ];

        // Mutation. Setter idempotent : si la valeur est déjà la bonne, c'est un no-op côté ORM.
        $monthly->setStripePriceId($this->monthlyPriceId);
        $yearly->setStripePriceId($this->yearlyPriceId);

        $this->em->flush();

        $io->table(['Plan', 'Avant', 'Après'], $rows);
        $io->success('2 plans synchronisés.');

        return Command::SUCCESS;
    }

    /**
     * Rend lisible un stripePriceId qui pourrait être null (affiché "—" dans le tableau).
     */
    private function formatId(?string $id): string
    {
        return $id === null || $id === '' ? '—' : $id;
    }
}
