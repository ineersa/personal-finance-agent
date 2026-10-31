<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Category;
use App\Entity\Source;
use App\Entity\Statement;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\Direction;
use Doctrine\ORM\EntityManagerInterface;
use Playwright\Page\PageInterface;
use Playwright\Symfony\Test\PlaywrightTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

abstract class FinanceBrowserTest extends PlaywrightTestCase
{
    protected const string PASSWORD = 'Synthetic-browser-password-05';
    protected int $aliceSource;
    protected int $bobSource;
    protected int $aliceTransaction;
    protected int $bobTransaction;
    protected int $draftTransaction;
    protected int $bobStatement;
    protected int $bobUser;
    protected int $category;
    /** @var array<string, int> */
    protected array $originalCurrencyTransactions = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $container = self::getContainer();
        // Only test burst state is reset. No daily accounting exists in this task.
        $container->get('cache.rate_limiter')->clear();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $alice = (new User())->setEmail('alice@example.test');
        $bob = (new User())->setEmail('bob@example.test');
        $admin = (new User())->setEmail('admin@example.test')->setRoles(['ROLE_ADMIN']);
        foreach ([$alice, $bob, $admin] as $user) {
            $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
            $em->persist($user);
        }
        $category = new Category('Synthetic shared category');
        $em->persist($category);
        $aliceSource = new Source($alice, 'Alice account');
        $bobSource = new Source($bob, 'Bob secret account');
        $em->persist($aliceSource);
        $em->persist($bobSource);
        $statements = [];
        $transactions = [];
        foreach ([[$alice, $aliceSource, 'alice'], [$bob, $bobSource, 'bob']] as [$user, $source, $name]) {
            // Synthetic metadata only, never a real uploaded file or download fixture.
            $statement = new Statement($user, $source, 'synthetic-'.$name, $name.'-synthetic.csv', 'text/csv', 0, str_repeat('0', 64));
            $transaction = new Transaction($user, $statement, $source, new \DateTimeImmutable('2026-10-01'), '+00123456789012.12345678', 'USD', Direction::Debit, $name.' approved transaction', $category, false);
            $em->persist($statement);
            $em->persist($transaction);
            $statements[] = $statement;
            $transactions[] = $transaction;
        }
        $draft = new Transaction($alice, $statements[0], $aliceSource, description: 'Unapproved draft');
        $em->persist($draft);
        $originals = [];
        foreach (['UAH', 'BGN'] as $currency) {
            $original = new Transaction($alice, $statements[0], $aliceSource, new \DateTimeImmutable('2025-10-01'), '10.00', $currency, Direction::Debit, 'Original '.$currency, $category, false);
            $em->persist($original);
            $originals[$currency] = $original;
        }
        $em->flush();
        $this->category = $category->getId();
        foreach ($originals as $currency => $original) {
            $this->originalCurrencyTransactions[$currency] = $original->getId();
        }
        $this->aliceSource = $aliceSource->getId();
        $this->bobSource = $bobSource->getId();
        $this->aliceTransaction = $transactions[0]->getId();
        $this->bobTransaction = $transactions[1]->getId();
        $this->draftTransaction = $draft->getId();
        $this->bobStatement = $statements[1]->getId();
        $this->bobUser = $bob->getId();
    }

    protected function storedPasswordHash(string $email): string
    {
        $hash = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT password FROM user WHERE email = ?', [$email]);
        self::assertIsString($hash);

        return $hash;
    }

    protected function openDeleteConfirmation(PageInterface $page): void
    {
        $runtime = $page->evaluate(<<<'JS'
            () => ({
                app: typeof window.EasyAdminApp,
                modal: typeof window.bootstrap?.Modal,
                assets: performance.getEntriesByType('resource')
                    .filter(entry => entry.name.includes('/bundles/easyadmin/'))
                    .slice(-12).map(entry => ({url: entry.name, status: entry.responseStatus}))
            })
            JS);
        $diagnostic = json_encode($runtime, \JSON_THROW_ON_ERROR);
        self::assertSame('object', $runtime['app'], 'EasyAdmin must initialize before opening confirmation. '.$diagnostic);
        self::assertSame('function', $runtime['modal'], 'Bootstrap modal code must load. '.$diagnostic);
        $page->locator('.action-delete')->click();
        try {
            $page->locator('#modal-action-confirmation-button')->waitFor(['state' => 'visible', 'timeout' => 5000]);
        } catch (\Throwable $exception) {
            $state = $page->evaluate(<<<'JS'
                () => ({
                    url: location.href,
                    trigger: document.querySelector('.action-delete')?.outerHTML,
                    modalClass: document.querySelector('#modal-action-confirmation')?.className,
                    modalDisplay: document.querySelector('#modal-action-confirmation')
                        ? getComputedStyle(document.querySelector('#modal-action-confirmation')).display : null,
                    confirmationButtons: document.querySelectorAll('#modal-action-confirmation-button').length
                })
                JS);
            self::fail('Delete confirmation did not become visible: '.json_encode($state, \JSON_THROW_ON_ERROR).' '.$exception->getMessage());
        }
    }

    protected function openSourceCreation(): PageInterface
    {
        $page = $this->getPage();
        // The bridge's last Symfony response may be a late subresource, not the document.
        $document = $page->goto($this->getBaseUrl().'/admin/source/new');
        self::assertNotNull($document);
        self::assertSame(200, $document->status(), 'Source creation document: '.substr($page->locator('body')->textContent() ?? '', 0, 1600));
        self::assertStringEndsWith('/favicon.svg', $page->locator('link[rel="shortcut icon"]')->getAttribute('href') ?? '');

        return $page;
    }

    protected function login(string $name): void
    {
        $page = $this->visit('/login');
        $this->submitLogin($page, $name.'@example.test', self::PASSWORD);
        $page->getByRole('heading', ['name' => 'Make sense of your money.', 'exact' => true])->waitFor();
    }

    protected function submitLogin(PageInterface $page, string $email, string $password): void
    {
        $page->getByLabel('Email', ['exact' => true])->fill($email);
        $page->getByLabel('Password', ['exact' => true])->fill($password);
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Log in', 'exact' => true])->click());
        $page->waitForLoadState();
    }

    protected function save(PageInterface $page): void
    {
        // submit() already awaits redirect navigation. Validation outcomes belong
        // to the scenario, not a second global load event after an invalid POST.
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
    }

    /** @param callable(): void $action */
    protected function submit(callable $action): void
    {
        $client = self::getPlaywrightClient();
        // Follow POST redirects explicitly: the pinned bridge's automatic path
        // uses a 204 navigation response, on which a locator click can stall.
        $client->followRedirects(false);
        try {
            $action();
            $response = $this->getLastResponse();
        } finally {
            $client->followRedirects();
        }
        if (null !== $response && $response->isRedirect()) {
            $client->followRedirect();
        }
    }
}
