<?php
namespace App\Controller;

use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Service\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Authentification et cycle de vie du compte utilisateur (préfixe `/api/auth`).
 *
 * Endpoints :
 *  - POST /register             (public)    inscription + envoi du lien de vérification d'email
 *  - POST /login                (public)    email + mot de passe → access token JWT + refresh token
 *  - POST /logout               (connecté)  révocation du refresh token
 *  - POST /refresh              (public)    rotation : nouveau couple access token / refresh token
 *  - POST /forgot-password      (public)    envoi d'un lien de réinitialisation (anti-énumération)
 *  - POST /reset-password       (public)    nouveau mot de passe à partir du jeton reçu par email
 *  - GET  /me                   (connecté)  profil de l'utilisateur connecté + état de son abonnement
 *  - GET  /verify-email/{token} (public)    confirmation de l'adresse email
 *
 * Les accès « public » / « connecté » sont posés par `access_control` dans
 * `config/packages/security.yaml` : ce contrôleur n'a pas de `#[IsGranted]`.
 *
 * Deux jetons coexistent :
 *  - l'access token : JWT signé RS256 par lexik, valable 15 min (`token_ttl: 900`).
 *    Son payload porte l'email dans le claim `username` (pas de `sub`) : le
 *    front ne le décode pas et appelle `GET /me` pour connaître l'utilisateur ;
 *  - le refresh token : chaîne opaque stockée en base (table `refresh_tokens`,
 *    bundle gesdinet), valable 30 jours, qui permet d'obtenir un nouvel access
 *    token sans redemander le mot de passe.
 *
 * Le login est codé à la main (le pare-feu `api` n'a pas de `json_login`) :
 * c'est ce contrôleur qui vérifie le mot de passe, refuse les comptes
 * suspendus et émet les deux jetons.
 */
#[Route('/api/auth')]
class AuthController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepo,
        private UserPasswordHasherInterface $hasher,
        private JWTTokenManagerInterface $tokenManager,
        private RefreshTokenManagerInterface $refreshTokenManager,
        private RefreshTokenGeneratorInterface $refreshTokenGenerator,
        private ValidatorInterface $validator,
        private MailerInterface $mailer,
        private SubscriptionRepository $subRepo,
        private SubscriptionService $subService,
        private LoggerInterface $logger,
    ) {}

    /**
     * Crée un compte utilisateur (rôle ROLE_USER, email non vérifié).
     *
     * Corps JSON : `email`, `password`, `firstName`, `lastName` (obligatoires)
     * et `consentRgpd` (facultatif, consentement RGPD enregistré tel quel).
     * Le mot de passe est haché avant stockage et un jeton de vérification est
     * envoyé par email. L'inscription ne connecte pas l'utilisateur : aucun JWT
     * n'est renvoyé, le front enchaîne sur `/login`.
     *
     * @return JsonResponse 201 `{message, user}` ; 400 si un champ obligatoire manque ou
     *                      si le mot de passe est trop court (< 8, mesuré par `strlen`) ;
     *                      409 si l'email est déjà utilisé
     */
    #[Route('/register', name: 'auth_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['email'], $data['password'], $data['firstName'], $data['lastName'])) {
            return $this->json(['message' => 'Champs requis manquants.'], 400);
        }

        if ($this->userRepo->findOneBy(['email' => $data['email']])) {
            return $this->json(['message' => 'Cette adresse email est déjà utilisée.'], 409);
        }

        if (strlen($data['password']) < 8) {
            return $this->json(['message' => 'Le mot de passe doit contenir au moins 8 caractères.'], 400);
        }

        // Le constructeur de User pose déjà l'UUID, les dates et le rôle ROLE_USER.
        $user = new User();
        $user->setEmail($data['email']);
        $user->setFirstName($data['firstName']);
        $user->setLastName($data['lastName']);
        $user->setPassword($this->hasher->hashPassword($user, $data['password']));
        // Jeton de vérification : 32 octets aléatoires (random_bytes, sûr pour la
        // cryptographie) → 64 caractères hexadécimaux, effacé une fois l'email vérifié.
        $user->setVerificationToken(bin2hex(random_bytes(32)));
        if (isset($data['consentRgpd'])) {
            $user->setConsentRgpd((bool)$data['consentRgpd']);
        }

        $this->em->persist($user);
        $this->em->flush();

        // Email de vérification (null transport en dev, pas d'erreur).
        // Le lien vise le front, dont l'URL (http://localhost:3000) est écrite en dur.
        try {
            $email = (new Email())
                ->from('noreply@cinaf.com')
                ->to($user->getEmail())
                ->subject('Vérifiez votre compte CINAF')
                ->text('Cliquez sur ce lien pour vérifier votre compte : http://localhost:3000/verify-email?token=' . $user->getVerificationToken());
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            // En dev (null transport) ou si le SMTP est injoignable : on n'échoue
            // pas l'inscription, mais on trace l'échec d'envoi de l'email de vérif.
            $this->logger->warning("Échec de l'envoi de l'email de vérification.", [
                'email' => $user->getEmail(),
                'exception' => $e->getMessage(),
            ]);
        }

        return $this->json([
            'message' => 'Inscription réussie. Vérifiez votre email.',
            'user' => $user->toArray(),
        ], 201);
    }

    /**
     * Connecte un utilisateur : vérifie ses identifiants puis émet un access token
     * JWT (15 min) et un refresh token (30 jours, enregistré en base).
     *
     * Corps JSON : `{email, password}`.
     * Email inconnu et mauvais mot de passe renvoient volontairement le même
     * message (401) pour ne pas révéler quels emails ont un compte. La suspension
     * n'est testée qu'APRÈS le mot de passe : seul le titulaire du compte apprend
     * qu'il est suspendu. Un email non vérifié n'empêche pas la connexion.
     *
     * @return JsonResponse 200 `{access_token, refresh_token, user}` ; 400 si l'email ou le
     *                      mot de passe manque ; 401 identifiants incorrects ; 403 compte suspendu
     */
    #[Route('/login', name: 'auth_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        if (!$email || !$password) {
            return $this->json(['message' => 'Email et mot de passe requis.'], 400);
        }

        $user = $this->userRepo->findOneBy(['email' => $email]);

        if (!$user || !$this->hasher->isPasswordValid($user, $password)) {
            return $this->json(['message' => 'Identifiants incorrects.'], 401);
        }

        if ($user->isSuspended()) {
            return $this->json(['message' => 'Ce compte est suspendu. Contactez le support.'], 403);
        }

        $accessToken = $this->tokenManager->create($user);
        // 2 592 000 s = 30 jours. Le refresh token n'est utilisable qu'une fois
        // persisté dans la table `refresh_tokens`.
        $refreshToken = $this->refreshTokenGenerator->createForUserWithTtl($user, 2592000);
        $this->refreshTokenManager->save($refreshToken);

        return $this->json([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken->getRefreshToken(),
            'user' => $user->toArray(),
        ]);
    }

    /**
     * Déconnecte l'utilisateur en révoquant son refresh token (suppression en base).
     *
     * L'access token JWT est sans état : le serveur ne peut pas l'invalider, il
     * reste accepté jusqu'à son expiration (15 min au plus). C'est au front de
     * l'oublier. Le refresh token est lu dans le corps JSON (`refresh_token`) ou,
     * à défaut, dans le cookie du même nom ; un jeton absent ou inconnu n'est
     * pas une erreur.
     *
     * @return JsonResponse 204 dans tous les cas (un JWT valide est exigé par access_control)
     */
    #[Route('/logout', name: 'auth_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        // Récupérer le refresh_token depuis le body ou les cookies
        $data = json_decode($request->getContent(), true);
        $refreshTokenString = $data['refresh_token'] ?? $request->cookies->get('refresh_token');

        if ($refreshTokenString) {
            $refreshToken = $this->refreshTokenManager->get($refreshTokenString);
            if ($refreshToken) {
                $this->refreshTokenManager->delete($refreshToken);
            }
        }

        return new JsonResponse(null, 204);
    }

    /**
     * Renouvelle la session : échange un refresh token valide contre un nouvel
     * access token JWT et un NOUVEAU refresh token (rotation).
     *
     * L'ancien refresh token est supprimé : chacun ne sert qu'une fois, ce qui
     * réduit la fenêtre d'exploitation d'un jeton volé. Le jeton est lu dans le
     * corps JSON (`refresh_token`) ou, à défaut, dans le cookie du même nom.
     * La route est publique (aucun JWT exigé) : elle doit fonctionner alors que
     * l'access token a déjà expiré.
     *
     * @return JsonResponse 200 `{access_token, refresh_token}` ; 400 si aucun refresh token ;
     *                      401 si le jeton est inconnu ou expiré, ou si son utilisateur n'existe plus
     */
    #[Route('/refresh', name: 'auth_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $refreshTokenString = $data['refresh_token'] ?? $request->cookies->get('refresh_token');

        if (!$refreshTokenString) {
            return $this->json(['message' => 'Refresh token manquant.'], 400);
        }

        $refreshToken = $this->refreshTokenManager->get($refreshTokenString);

        if (!$refreshToken || !$refreshToken->isValid()) {
            return $this->json(['message' => 'Refresh token invalide ou expiré.'], 401);
        }

        // Le refresh token gesdinet (v2) mémorise l'email de son propriétaire, exposé
        // par getUsername() (ce modèle n'a pas de getUserIdentifier()).
        $user = $this->userRepo->findOneBy(['email' => $refreshToken->getUsername()]);
        if (!$user) {
            return $this->json(['message' => 'Utilisateur introuvable.'], 401);
        }

        // Rotation : le nouveau refresh token remplace l'ancien, supprimé de la base.
        $newAccessToken = $this->tokenManager->create($user);
        $newRefreshToken = $this->refreshTokenGenerator->createForUserWithTtl($user, 2592000);
        $this->refreshTokenManager->delete($refreshToken);
        $this->refreshTokenManager->save($newRefreshToken);

        return $this->json([
            'access_token' => $newAccessToken,
            'refresh_token' => $newRefreshToken->getRefreshToken(),
        ]);
    }

    /**
     * Lance la réinitialisation du mot de passe : si l'email correspond à un
     * compte, génère un jeton valable 1 heure et l'envoie par email.
     *
     * Anti-énumération : la réponse est identique (200, même message) que
     * l'email soit vide, inconnu ou connu, et même si l'envoi échoue ; un
     * attaquant ne peut donc pas tester quelles adresses sont inscrites.
     * Une nouvelle demande écrase le jeton précédent.
     *
     * @return JsonResponse 200 `{message}` dans tous les cas
     */
    #[Route('/forgot-password', name: 'auth_forgot_password', methods: ['POST'])]
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $email = trim($data['email'] ?? '');

        // Anti-énumération : toujours retourner 200
        if (!$email) {
            return $this->json(['message' => 'Si cet email existe, un lien de réinitialisation a été envoyé.']);
        }

        $user = $this->userRepo->findOneBy(['email' => $email]);

        if ($user) {
            // Jeton aléatoire de 64 caractères hexadécimaux, stocké sur l'utilisateur
            // avec sa date d'expiration (contrôlée par resetPassword()).
            $token = bin2hex(random_bytes(32));
            $user->setPasswordResetToken($token);
            $user->setPasswordResetTokenExpiry(new \DateTimeImmutable('+1 hour'));
            $this->em->flush();

            try {
                $emailMsg = (new Email())
                    ->from('noreply@cinaf.com')
                    ->to($email)
                    ->subject('Réinitialisation de votre mot de passe CINAF')
                    ->text('Cliquez sur ce lien (valable 1h) : http://localhost:3000/reset-password?token=' . $token);
                $this->mailer->send($emailMsg);
            } catch (TransportExceptionInterface $e) {
                // Échec d'envoi non bloquant (réponse identique que l'email existe
                // ou non, pour ne pas divulguer l'existence du compte), mais tracé.
                $this->logger->warning("Échec de l'envoi de l'email de réinitialisation.", [
                    'email' => $email,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $this->json(['message' => 'Si cet email existe, un lien de réinitialisation a été envoyé.']);
    }

    /**
     * Termine la réinitialisation : remplace le mot de passe du compte qui
     * possède le jeton reçu par email, si ce jeton n'a pas expiré.
     *
     * Corps JSON : `{token, password}`. Le jeton et son expiration sont effacés
     * après usage (usage unique). Les refresh tokens déjà émis ne sont pas révoqués.
     *
     * @return JsonResponse 200 `{message}` ; 400 si un champ manque, si le mot de passe est
     *                      trop court (< 8, mesuré par `strlen`) ou si le jeton est invalide ou expiré
     */
    #[Route('/reset-password', name: 'auth_reset_password', methods: ['POST'])]
    public function resetPassword(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $token = $data['token'] ?? '';
        $password = $data['password'] ?? '';

        if (!$token || !$password) {
            return $this->json(['message' => 'Token et nouveau mot de passe requis.'], 400);
        }

        if (strlen($password) < 8) {
            return $this->json(['message' => 'Le mot de passe doit contenir au moins 8 caractères.'], 400);
        }

        $user = $this->userRepo->findOneBy(['passwordResetToken' => $token]);

        if (!$user || !$user->getPasswordResetTokenExpiry() || $user->getPasswordResetTokenExpiry() < new \DateTimeImmutable()) {
            return $this->json(['message' => 'Token invalide ou expiré.'], 400);
        }

        $user->setPassword($this->hasher->hashPassword($user, $password));
        $user->setPasswordResetToken(null);
        $user->setPasswordResetTokenExpiry(null);
        $this->em->flush();

        return $this->json(['message' => 'Mot de passe réinitialisé avec succès.']);
    }

    /**
     * Renvoie le profil de l'utilisateur connecté, enrichi de l'état de son abonnement.
     *
     * C'est la source de vérité du front sur « qui est connecté » : le JWT ne
     * porte que l'email (claim `username`) et le front ne le décode jamais.
     * `hasActiveSubscription` reste vrai pendant une résiliation différée, tant
     * que la période payée n'est pas terminée (statut ACTIVE, `endsAt` futur).
     *
     * @return JsonResponse 200 `User::toArray()` + `hasActiveSubscription` (bool) + `subscription`
     *                      (résumé SubscriptionService::summarize(), ou null) ; 401 si non authentifié
     */
    #[Route('/me', name: 'auth_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'Non authentifié.'], 401);
        }

        $sub = $this->subRepo->findCurrentActiveForUser($user);
        $payload = $user->toArray();
        $payload['hasActiveSubscription'] = $sub !== null && $sub->isCurrentlyActive();
        $payload['subscription'] = $this->subService->summarize($sub);

        return $this->json($payload);
    }

    /**
     * Confirme l'adresse email grâce au jeton envoyé lors de l'inscription.
     *
     * Le jeton est effacé après usage : un second appel avec le même jeton renvoie 400.
     *
     * @return JsonResponse 200 `{message}` ; 400 si le jeton est inconnu ou déjà utilisé
     */
    #[Route('/verify-email/{token}', name: 'auth_verify_email', methods: ['GET'])]
    public function verifyEmail(string $token): JsonResponse
    {
        $user = $this->userRepo->findOneBy(['verificationToken' => $token]);

        if (!$user) {
            return $this->json(['message' => 'Token de vérification invalide.'], 400);
        }

        $user->setIsVerified(true);
        $user->setVerificationToken(null);
        $this->em->flush();

        return $this->json(['message' => 'Email vérifié avec succès.']);
    }
}
