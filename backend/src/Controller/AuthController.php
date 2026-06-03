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

        $user = new User();
        $user->setEmail($data['email']);
        $user->setFirstName($data['firstName']);
        $user->setLastName($data['lastName']);
        $user->setPassword($this->hasher->hashPassword($user, $data['password']));
        $user->setVerificationToken(bin2hex(random_bytes(32)));
        if (isset($data['consentRgpd'])) {
            $user->setConsentRgpd((bool)$data['consentRgpd']);
        }

        $this->em->persist($user);
        $this->em->flush();

        // Email de vérification (null transport en dev, pas d'erreur)
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
        $refreshToken = $this->refreshTokenGenerator->createForUserWithTtl($user, 2592000);
        $this->refreshTokenManager->save($refreshToken);

        return $this->json([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken->getRefreshToken(),
            'user' => $user->toArray(),
        ]);
    }

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

        $user = $this->userRepo->findOneBy(['email' => $refreshToken->getUsername()]);
        if (!$user) {
            return $this->json(['message' => 'Utilisateur introuvable.'], 401);
        }

        $newAccessToken = $this->tokenManager->create($user);
        $newRefreshToken = $this->refreshTokenGenerator->createForUserWithTtl($user, 2592000);
        $this->refreshTokenManager->delete($refreshToken);
        $this->refreshTokenManager->save($newRefreshToken);

        return $this->json([
            'access_token' => $newAccessToken,
            'refresh_token' => $newRefreshToken->getRefreshToken(),
        ]);
    }

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
