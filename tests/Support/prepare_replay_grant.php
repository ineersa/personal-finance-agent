<?php

declare(strict_types=1);

use App\Entity\User;
use App\Kernel;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthFileStore;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthRecord;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthConfig;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

require dirname(__DIR__).'/bootstrap.php';

if ('test' !== ($_SERVER['APP_ENV'] ?? getenv('APP_ENV')) || filter_var($_SERVER['PROVIDER_CASSETTE_RECORD'] ?? getenv('PROVIDER_CASSETTE_RECORD'), \FILTER_VALIDATE_BOOL)) {
    throw new LogicException('Synthetic grant preparation requires the isolated test stack in replay mode.');
}

$kernel = new Kernel('test', false);
try {
    $kernel->boot();
    $container = $kernel->getContainer();
    $doctrine = $container->get('doctrine');
    if (!$doctrine instanceof ManagerRegistry) {
        throw new LogicException('Doctrine is unavailable for canonical fixture lookup.');
    }
    $manager = $doctrine->getManagerForClass(User::class) ?? throw new LogicException('The canonical user manager is unavailable.');
    $user = $manager->getRepository(User::class)->findOneBy(['email' => 'demo@test.com']);
    if (!$user instanceof User || null === $identity = $user->getChatGptRegistrationId()) {
        throw new LogicException('Load the canonical demo fixture with its native ChatGPT identity before preparing replay.');
    }
    $directory = $kernel->getProjectDir().'/var/private/chatgpt-replay-auth';
    $filesystem = new Filesystem();
    $filesystem->mkdir($directory, 0700);
    $filesystem->chmod($directory, 0700);
    $store = new AuthFileStore($directory.'/'.$identity.'.json', new LockFactory(new FlockStore($directory)), $filesystem, $container->getParameter('app.chatgpt.installation_id'));
    $record = new AuthRecord(
        $container->getParameter('app.chatgpt.client_id'),
        'synthetic-replay-access',
        'synthetic-replay-refresh',
        time() + 86400,
        'synthetic.e30.synthetic',
        OAuthConfig::ISSUER,
        'synthetic-replay-subject',
        [OAuthConfig::DIRECT_SCOPE],
        'synthetic-replay-nonce',
    );
    $store->update(static fn (): AuthRecord => $record);
    echo "Prepared canonical demo synthetic grant in replay-only AuthFileStore; real grants untouched.\n";
} finally {
    $kernel->shutdown();
}
