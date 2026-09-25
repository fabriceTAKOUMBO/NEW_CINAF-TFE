<?php
namespace App\DataFixtures;

use App\Entity\SubscriptionPlan;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Plans d'abonnement par défaut (idempotent — ne recrée pas si déjà en DB).
 *
 * - Mensuel : 9.99€ / mois
 * - Annuel : 99€ / an (≈ 17% de réduction par rapport au mensuel)
 *
 * Le nom du plan (« Mensuel » / « Annuel ») sert de clé d'idempotence ; la
 * commande `app:stripe:sync-plans` s'appuie sur ces mêmes noms. Les prix sont
 * stockés en centimes, dans la devise par défaut de SubscriptionPlan (EUR).
 * Contrairement à AppFixtures et StudioFixtures, aucune restriction
 * d'environnement n'est codée ici.
 */
class SubscriptionPlanFixtures extends Fixture
{
    /**
     * Crée les plans absents et complète, sur les plans existants, un
     * `stripePriceId` encore vide lorsque la variable d'environnement est renseignée.
     */
    public function load(ObjectManager $manager): void
    {
        $repo = $manager->getRepository(SubscriptionPlan::class);

        // Les stripePriceId proviennent des vars d'env (renseignées en .env.local après
        // création des prices côté dashboard Stripe). Null tant que vide → mode mock.
        $monthlyPriceId = $_ENV['STRIPE_PRICE_MONTHLY'] ?? '';
        $yearlyPriceId = $_ENV['STRIPE_PRICE_YEARLY'] ?? '';

        $plans = [
            [
                'name' => 'Mensuel',
                'description' => "Accès illimité au catalogue, facturation mensuelle.",
                'priceCents' => 999,
                'intervalUnit' => SubscriptionPlan::INTERVAL_MONTH,
                'intervalCount' => 1,
                'features' => [
                    'Accès illimité au catalogue',
                    'Streaming HD adaptatif',
                    'Annulation à tout moment',
                ],
                'stripePriceId' => $monthlyPriceId !== '' ? $monthlyPriceId : null,
            ],
            [
                'name' => 'Annuel',
                'description' => "Accès illimité au catalogue, facturation annuelle (économisez 17%).",
                'priceCents' => 9900,
                'intervalUnit' => SubscriptionPlan::INTERVAL_YEAR,
                'intervalCount' => 1,
                'features' => [
                    'Accès illimité au catalogue',
                    'Streaming HD adaptatif',
                    '12 mois pour le prix de 10',
                    'Annulation à tout moment',
                ],
                'stripePriceId' => $yearlyPriceId !== '' ? $yearlyPriceId : null,
            ],
        ];

        foreach ($plans as $data) {
            $existing = $repo->findOneBy(['name' => $data['name']]);
            if ($existing) {
                // Si le plan existe déjà mais que le stripePriceId vient d'être renseigné en env,
                // on rétro-applique pour éviter de devoir purger la table en dev.
                // Un stripePriceId déjà présent n'est jamais remplacé (changement de
                // compte Stripe : utiliser `app:stripe:sync-plans`).
                if ($existing->getStripePriceId() === null && $data['stripePriceId'] !== null) {
                    $existing->setStripePriceId($data['stripePriceId']);
                }
                continue;
            }
            $plan = new SubscriptionPlan();
            $plan->setName($data['name']);
            $plan->setDescription($data['description']);
            $plan->setPriceCents($data['priceCents']);
            $plan->setIntervalUnit($data['intervalUnit']);
            $plan->setIntervalCount($data['intervalCount']);
            $plan->setFeatures($data['features']);
            $plan->setIsActive(true);
            if ($data['stripePriceId'] !== null) {
                $plan->setStripePriceId($data['stripePriceId']);
            }
            $manager->persist($plan);
        }

        $manager->flush();
    }
}
