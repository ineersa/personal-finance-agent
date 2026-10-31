<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use App\Tests\Support\FinanceBrowserTest;
use App\Tests\Support\SubmitsBrowserForms;
use Doctrine\ORM\EntityManagerInterface;
use Hautelook\AliceBundle\PhpUnit\FixtureStore;
use Playwright\Page\PageInterface;

final class UserPasswordTest extends FinanceBrowserTest
{
    use SubmitsBrowserForms;

    private const string PASSWORD = 'Synthetic-browser-password-05';

    public function testNativePasswordFormLifecycleAndRootInvalidSubmission(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $email = 'password-lifecycle@example.test';
        $changedPassword = 'Changed-synthetic-password-05';
        $this->loginUser($fixtures['admin']);
        $page = $this->visit('/home/user/new');
        $page->getByLabel('Email', ['exact' => true])->fill($email);
        $page->getByLabel('New password', ['exact' => true])->fill(self::PASSWORD);
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        $page = $this->visit('/home/user');
        $editUrl = $page->locator('tbody tr')->filter(['hasText' => $email])->locator('a.action-edit')->getAttribute('href');
        self::assertNotNull($editUrl);
        $originalHash = $this->storedPasswordHash($email);
        self::assertNotSame(self::PASSWORD, $originalHash);
        $page = $this->submitLogin('password-lifecycle@example.test', self::PASSWORD);
        $page->locator('.finance-chat .chat-empty')->waitFor();
        $this->loginUser($fixtures['admin']);
        $page->goto($editUrl);
        $page->getByLabel('Email', ['exact' => true])->fill('password-renamed@example.test');
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        self::assertSame($originalHash, $this->storedPasswordHash('password-renamed@example.test'));
        $page = $this->submitLogin('password-renamed@example.test', self::PASSWORD);
        $page->locator('.finance-chat .chat-empty')->waitFor();
        $this->loginUser($fixtures['admin']);
        $page->goto($editUrl);
        // A valid password must not be hashed when another root field is invalid.
        $page->getByLabel('Email', ['exact' => true])->fill('invalid-email');
        $page->getByLabel('New password', ['exact' => true])->fill($changedPassword);
        $page->evaluate('() => document.querySelector("form[name=User]").noValidate = true');
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        $this->assertPageContains('This value is not a valid email address');
        self::assertSame($originalHash, $this->storedPasswordHash('password-renamed@example.test'));
        $boundUser = self::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['email' => 'password-renamed@example.test']);
        self::assertInstanceOf(User::class, $boundUser);
        self::assertSame($originalHash, $boundUser->getPassword());
        $page = $this->submitLogin('password-renamed@example.test', self::PASSWORD);
        $page->locator('.finance-chat .chat-empty')->waitFor();
        $this->loginUser($fixtures['admin']);
        $page->goto($editUrl);
        $page->getByLabel('New password', ['exact' => true])->fill($changedPassword);
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        self::assertNotSame($originalHash, $this->storedPasswordHash('password-renamed@example.test'));
        $page = $this->submitLogin('password-renamed@example.test', self::PASSWORD);
        $this->assertPageContains('Invalid credentials');
        $page = $this->submitLogin('password-renamed@example.test', $changedPassword);
        $page->getByRole('heading', ['name' => 'Make sense of your money.', 'exact' => true])->waitFor();
    }

    public function testPasswordCharacterMinimumAndMultibyteByteMaximum(): void
    {
        $fixtures = FixtureStore::getFixtures();
        $this->loginUser($fixtures['admin']);
        $page = $this->visit('/home/user/new');
        $page->getByLabel('Email', ['exact' => true])->fill('password-limits@example.test');
        $page->evaluate('() => document.querySelector("form[name=User]").noValidate = true');
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        $page->getByText('This value should not be blank')->waitFor(['state' => 'visible', 'timeout' => 5000]);
        $this->assertPageContains('This value should not be blank');
        $page->getByLabel('New password', ['exact' => true])->fill(str_repeat('界', 11));
        $page->evaluate('() => document.querySelector("form[name=User]").noValidate = true');
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        $page->getByText('12 characters or more')->waitFor(['state' => 'visible', 'timeout' => 5000]);
        $this->assertPageContains('12 characters or more');
        // 1,400 Unicode characters fit the character limit but exceed 4,096 bytes.
        $oversized = str_repeat('界', 1400);
        $page->getByLabel('New password', ['exact' => true])->fill($oversized);
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        $page->getByText('4096 bytes or less')->waitFor(['state' => 'visible', 'timeout' => 5000]);
        $this->assertPageContains('4096 bytes or less');
        $page = $this->visit('/home/user/'.$fixtures['bob']->getId().'/edit');
        $originalHash = $this->storedPasswordHash('bob@example.test');
        $page->getByLabel('New password', ['exact' => true])->fill($oversized);
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        $page->getByText('4096 bytes or less')->waitFor(['state' => 'visible', 'timeout' => 5000]);
        $this->assertPageContains('4096 bytes or less');
        self::assertSame($originalHash, $this->storedPasswordHash('bob@example.test'));
        // Twelve characters, even when multibyte, satisfy the character minimum.
        $accepted = str_repeat('界', 12);
        $page = $this->visit('/home/user/new');
        $page->getByLabel('Email', ['exact' => true])->fill('password-limits@example.test');
        $page->getByLabel('New password', ['exact' => true])->fill($accepted);
        $this->submit(static fn () => $page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click());
        $page = $this->submitLogin('password-limits@example.test', $accepted);
        $page->getByRole('heading', ['name' => 'Make sense of your money.', 'exact' => true])->waitFor();
    }

    private function submitLogin(string $email, string $password): PageInterface
    {
        $page = $this->visit('/login');
        $page->getByLabel('Email', ['exact' => true])->fill($email);
        $page->getByLabel('Password', ['exact' => true])->fill($password);
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Log in', 'exact' => true])->click());

        return $page;
    }

    private function storedPasswordHash(string $email): string
    {
        $hash = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT password FROM user WHERE email = ?', [$email]);
        self::assertIsString($hash);

        return $hash;
    }
}
