<?php

declare(strict_types=1);

namespace App\Tests\E2E;

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

final class FinanceAccessTest extends PlaywrightTestCase
{
    private const string PASSWORD = 'Synthetic-browser-password-05';
    private int $aliceSource;
    private int $bobSource;
    private int $aliceTransaction;
    private int $bobTransaction;
    private int $draftTransaction;
    private int $bobStatement;
    private int $bobUser;

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
        $em->flush();
        $this->aliceSource = $aliceSource->getId();
        $this->bobSource = $bobSource->getId();
        $this->aliceTransaction = $transactions[0]->getId();
        $this->bobTransaction = $transactions[1]->getId();
        $this->draftTransaction = $draft->getId();
        $this->bobStatement = $statements[1]->getId();
        $this->bobUser = $bob->getId();
    }

    public function testRealLoginLogoutAndProtectedPages(): void
    {
        $page = $this->visit('/admin/source');
        self::assertTrue($page->getByRole('button', ['name' => 'Log in', 'exact' => true])->isVisible());
        $this->submitLogin($page, 'alice@example.test', 'incorrect-password');
        $this->assertPageContains('Invalid credentials');
        $this->submitLogin($page, 'alice@example.test', self::PASSWORD);
        $page->waitForURL('**/admin/source');
        $this->assertPageContains('Alice account');
        self::assertStringNotContainsString('Bob secret account', $page->locator('body')->textContent());
        $page = $this->visit('/settings');
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Log out', 'exact' => true])->click());
        $page->waitForURL('**/login');
        $this->visit('/settings');
        $this->assertPageContains('Personal finance login');
    }

    public function testLoginThrottlingCannotBeBypassedByForwardedHeaders(): void
    {
        $page = $this->visit('/login');
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->submitLogin($page, 'alice@example.test', 'incorrect-password');
            $this->assertPageContains('Invalid credentials');
        }
        // Browser supplies hostile headers. No configured proxy may promote them.
        $page->setExtraHTTPHeaders(['X-Forwarded-For' => '203.0.113.55', 'Forwarded' => 'for=203.0.113.55']);
        $this->submitLogin($page, 'alice@example.test', self::PASSWORD);
        $this->assertPageContains('Too many failed login attempts');
        self::assertTrue($page->getByRole('button', ['name' => 'Log in', 'exact' => true])->isVisible());
    }

    public function testOwnerScopeDirectUrlsAndAdminHaveNoFinanceBypass(): void
    {
        foreach (['alice', 'admin'] as $identity) {
            $this->login($identity);
            foreach (['/admin/source/'.$this->bobSource.'/edit', '/admin/source/'.$this->bobSource, '/admin/transaction/'.$this->bobTransaction.'/edit', '/admin/statement/'.$this->bobStatement] as $path) {
                $page = $this->visit($path);
                self::assertSame(403, $this->getLastResponse()->getStatusCode(), $path);
                self::assertStringNotContainsString('Bob secret account', $page->locator('body')->textContent());
            }
            foreach (['source', 'transaction', 'statement'] as $screen) {
                $page = $this->visit('/admin/'.$screen);
                self::assertStringNotContainsString('Bob secret account', $page->locator('body')->textContent());
                self::assertStringNotContainsString('bob approved transaction', $page->locator('body')->textContent());
                self::assertStringNotContainsString('bob-synthetic.csv', $page->locator('body')->textContent());
            }
        }
        $this->login('bob');
        $this->visit('/admin/source');
        $this->assertPageContains('Bob secret account');
        self::assertStringNotContainsString('Alice account', $this->getPage()->locator('body')->textContent());
        $this->visit('/admin/transaction/'.$this->bobTransaction.'/edit');
        self::assertSame('+00123456789012.12345678', $this->getPage()->getByLabel('Amount', ['exact' => true])->inputValue());
    }

    public function testUserCannotManageUsersCategoriesOrDrafts(): void
    {
        $this->login('alice');
        foreach (['/admin/user', '/admin/user/new', '/admin/user/'.$this->bobUser.'/edit', '/admin/category', '/admin/category/new', '/admin/transaction/'.$this->draftTransaction.'/edit', '/admin/transaction/new', '/admin/statement/new'] as $path) {
            $this->visit($path);
            self::assertSame(403, $this->getLastResponse()->getStatusCode(), $path);
        }
    }

    public function testSourceCrudValidationEscapingAndReload(): void
    {
        $this->login('alice');
        $page = $this->visit('/admin/source/new');
        $page->getByLabel('Name', ['exact' => true])->fill('<script>window.financeInjected=true</script>');
        $page->getByLabel('Description', ['exact' => true])->fill('Synthetic source created through the browser');
        $this->save($page);
        $page = $this->visit('/admin/source');
        self::assertStringContainsString('<script>window.financeInjected=true</script>', $page->locator('body')->textContent());
        self::assertNull($page->evaluate('() => window.financeInjected'));
        $page = $this->visit('/admin/source/'.$this->aliceSource.'/edit');
        $page->getByLabel('Name', ['exact' => true])->fill('Alice renamed account');
        $this->save($page);
        $page = $this->visit('/admin/source/'.$this->aliceSource.'/edit');
        self::assertSame('Alice renamed account', $page->getByLabel('Name', ['exact' => true])->inputValue());
        $page->getByLabel('Name', ['exact' => true])->fill('');
        $page->evaluate('() => document.querySelector("form[name=Source]").noValidate = true');
        $this->save($page);
        $this->assertPageContains('This value should not be blank');
    }

    public function testApprovedEditExactMoneyCurrencySettingsAndAssociationAttack(): void
    {
        $this->login('alice');
        $page = $this->visit('/admin/transaction/'.$this->aliceTransaction.'/edit');
        self::assertSame('+00123456789012.12345678', $page->getByLabel('Amount', ['exact' => true])->inputValue());
        self::assertStringNotContainsString('Bob secret account', $page->locator('select[name="Transaction[source]"]')->textContent());
        $page->getByLabel('Amount', ['exact' => true])->fill('-000123456789012.123456789012345678');
        $page->getByLabel('Description', ['exact' => true])->fill('Edited approved transaction');
        $this->save($page);
        $page = $this->visit('/admin/transaction/'.$this->aliceTransaction.'/edit');
        self::assertSame('-000123456789012.123456789012345678', $page->getByLabel('Amount', ['exact' => true])->inputValue());
        $page->getByLabel('Amount', ['exact' => true])->fill('1e20');
        $this->save($page);
        $this->assertPageContains('This value is not valid');
        $page = $this->visit('/settings');
        self::assertSame('USD', $page->getByLabel('Reporting currency')->inputValue());
        $page->getByLabel('Reporting currency')->selectOption('EUR');
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Save preferences'])->click());
        $this->assertPageContains('Reporting currency saved');
        $page = $this->visit('/settings');
        self::assertSame('EUR', $page->getByLabel('Reporting currency')->inputValue());
        $page = $this->visit('/admin/transaction/'.$this->aliceTransaction.'/edit');
        self::assertSame('-000123456789012.123456789012345678', $page->getByLabel('Amount', ['exact' => true])->inputValue());
        self::assertSame('USD', $page->locator('select[name="Transaction[currency]"]')->inputValue());
        $page->evaluate('(id) => { const s = document.querySelector("select[name=\"Transaction[source]\"]"); s.add(new Option("Forged foreign source", id, true, true)); }', (string) $this->bobSource);
        $this->save($page);
        $this->assertPageContains('The selected choice is invalid');
        $page = $this->visit('/admin/transaction/'.$this->aliceTransaction.'/edit');
        self::assertSame((string) $this->aliceSource, $page->locator('select[name="Transaction[source]"]')->inputValue());
    }

    public function testAdminManagesSharedCategoriesAndUsers(): void
    {
        $this->login('admin');
        $page = $this->visit('/admin/category/new');
        $page->getByLabel('Name', ['exact' => true])->fill('New shared catalog entry');
        $this->save($page);
        $page = $this->visit('/admin/category');
        $this->assertPageContains('New shared catalog entry');
        $categoryUrl = $page->getByRole('row')->filter(['hasText' => 'New shared catalog entry'])->locator('a.action-edit')->getAttribute('href');
        $page->goto($categoryUrl);
        $page->getByLabel('Name', ['exact' => true])->fill('Edited shared catalog entry');
        $this->save($page);
        $page->goto($categoryUrl);
        self::assertSame('Edited shared catalog entry', $page->getByLabel('Name', ['exact' => true])->inputValue());
        $page->locator('a.action-delete')->click();
        $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());
        $page->waitForLoadState();
        $page = $this->visit('/admin/category');
        self::assertStringNotContainsString('Edited shared catalog entry', $page->locator('body')->textContent());
        $page = $this->visit('/admin/user/new');
        $page->getByLabel('Email', ['exact' => true])->fill('created@example.test');
        $page->getByLabel('New password', ['exact' => true])->fill(self::PASSWORD);
        $this->save($page);
        $page = $this->visit('/admin/user');
        $this->assertPageContains('created@example.test');
        $userUrl = $page->getByRole('row')->filter(['hasText' => 'created@example.test'])->locator('a.action-edit')->getAttribute('href');
        $page->goto($userUrl);
        $page->getByLabel('Email', ['exact' => true])->fill('renamed@example.test');
        // An empty new-password field must preserve the existing hash.
        $this->save($page);
        $this->login('renamed');
        $this->visit('/settings');
        $this->assertPageContains('Settings');
        $this->login('admin');
        $page->goto($userUrl);
        $page->locator('a.action-delete')->click();
        $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());
        $page->waitForLoadState();
        $page = $this->visit('/admin/user');
        self::assertStringNotContainsString('renamed@example.test', $page->locator('body')->textContent());
    }

    public function testDatabaseRollbackLeavesNoPriorBrowserCreatedSource(): void
    {
        $this->login('alice');
        $page = $this->visit('/admin/source');
        self::assertStringNotContainsString('Synthetic source created through the browser', $page->locator('body')->textContent());
        self::assertStringNotContainsString('Alice renamed account', $page->locator('body')->textContent());
    }

    public function testSourceDeletionRequiresCsrfAndCannotTargetAnotherOwner(): void
    {
        $this->login('alice');
        $page = $this->visit('/admin/source/new');
        $page->getByLabel('Name', ['exact' => true])->fill('Disposable synthetic source');
        $this->save($page);
        $page = $this->visit('/admin/source');
        $row = $page->getByRole('row')->filter(['hasText' => 'Disposable synthetic source']);
        $editUrl = $row->locator('a.action-edit')->getAttribute('href');
        self::assertNotNull($editUrl);
        $page->goto($editUrl);
        $page->locator('.action-delete')->click();
        $page->evaluate('() => document.querySelector("#action-confirmation-form [name=token]").value = "forged"');
        $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());
        $page->waitForLoadState();
        $this->visit('/admin/source');
        $this->assertPageContains('Disposable synthetic source');
        $page->goto($editUrl);
        $page->evaluate('(path) => document.querySelector(".action-delete").setAttribute("formaction", path)', '/admin/source/'.$this->bobSource.'/delete');
        $page->locator('.action-delete')->click();
        $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());
        $page->waitForLoadState();
        self::assertSame(403, $this->getLastResponse()->getStatusCode());
        $page->goto($editUrl);
        $page->locator('a.action-delete')->click();
        $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());
        $page->waitForLoadState();
        $page = $this->visit('/admin/source');
        self::assertStringNotContainsString('Disposable synthetic source', $page->locator('body')->textContent());
        $page = $this->visit('/admin/source/'.$this->aliceSource.'/edit');
        $page->locator('a.action-delete')->click();
        $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());
        $page->waitForLoadState();
        $this->assertPageContains('This record is still referenced and was not deleted');
        $this->visit('/admin/source');
        $this->assertPageContains('Alice account');
    }

    public function testSettingsRejectUnsupportedCurrencyAndInvalidCsrf(): void
    {
        $this->login('alice');
        $page = $this->visit('/settings');
        $page->evaluate('() => { const s = document.querySelector("select[name=\"form[reportingCurrency]\"]"); s.add(new Option("Unsupported", "XXX", true, true)); }');
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Save preferences'])->click());
        $this->assertPageContains('The selected choice is invalid');
        $page = $this->visit('/settings');
        self::assertSame('USD', $page->getByLabel('Reporting currency')->inputValue());
        $page->getByLabel('Reporting currency')->selectOption('EUR');
        $page->evaluate('() => document.querySelector("input[name=\"form[_token]\"]").value = "invalid"');
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Save preferences'])->click());
        $this->assertPageContains('The CSRF token is invalid');
        $page = $this->visit('/settings');
        self::assertSame('USD', $page->getByLabel('Reporting currency')->inputValue());
    }

    private function login(string $name): void
    {
        $page = $this->visit('/login');
        $this->submitLogin($page, $name.'@example.test', self::PASSWORD);
        $page->getByRole('heading', ['name' => 'Make sense of your money.', 'exact' => true])->waitFor();
    }

    private function submitLogin(PageInterface $page, string $email, string $password): void
    {
        $page->getByLabel('Email', ['exact' => true])->fill($email);
        $page->getByLabel('Password', ['exact' => true])->fill($password);
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Log in', 'exact' => true])->click());
        $page->waitForLoadState();
    }

    private function save(PageInterface $page): void
    {
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        $page->waitForLoadState();
    }

    /** @param callable(): void $action */
    private function submit(callable $action): void
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
