<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use App\Tests\Support\FinanceBrowserTest;
use Doctrine\ORM\EntityManagerInterface;

final class UserPasswordTest extends FinanceBrowserTest
{
    public function testNativePasswordFormLifecycleAndRootInvalidSubmission(): void
    {
        $email = 'password-lifecycle@example.test';
        $changedPassword = 'Changed-synthetic-password-05';
        $this->login('admin');
        $page = $this->visit('/admin/user/new');
        $page->getByLabel('Email', ['exact' => true])->fill($email);
        $page->getByLabel('New password', ['exact' => true])->fill(self::PASSWORD);
        $this->save($page);
        $page = $this->visit('/admin/user');
        $editUrl = $page->locator('tbody tr')->filter(['hasText' => $email])->locator('a.action-edit')->getAttribute('href');
        self::assertNotNull($editUrl);
        $originalHash = $this->storedPasswordHash($email);
        self::assertNotSame(self::PASSWORD, $originalHash);
        $this->login('password-lifecycle');
        $this->login('admin');
        $page->goto($editUrl);
        $page->getByLabel('Email', ['exact' => true])->fill('password-renamed@example.test');
        $this->save($page);
        self::assertSame($originalHash, $this->storedPasswordHash('password-renamed@example.test'));
        $this->login('password-renamed');
        $this->login('admin');
        $page->goto($editUrl);
        // A valid password must not be hashed when another root field is invalid.
        $page->getByLabel('Email', ['exact' => true])->fill('invalid-email');
        $page->getByLabel('New password', ['exact' => true])->fill($changedPassword);
        $page->evaluate('() => document.querySelector("form[name=User]").noValidate = true');
        $this->save($page);
        $this->assertPageContains('This value is not a valid email address');
        self::assertSame($originalHash, $this->storedPasswordHash('password-renamed@example.test'));
        $boundUser = self::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['email' => 'password-renamed@example.test']);
        self::assertInstanceOf(User::class, $boundUser);
        self::assertSame($originalHash, $boundUser->getPassword());
        $this->login('password-renamed');
        $this->login('admin');
        $page->goto($editUrl);
        $page->getByLabel('New password', ['exact' => true])->fill($changedPassword);
        $this->save($page);
        self::assertNotSame($originalHash, $this->storedPasswordHash('password-renamed@example.test'));
        $page = $this->visit('/login');
        $this->submitLogin($page, 'password-renamed@example.test', self::PASSWORD);
        $this->assertPageContains('Invalid credentials');
        $this->submitLogin($page, 'password-renamed@example.test', $changedPassword);
        $page->getByRole('heading', ['name' => 'Make sense of your money.', 'exact' => true])->waitFor();
    }

    public function testPasswordCharacterMinimumAndMultibyteByteMaximum(): void
    {
        $this->login('admin');
        $page = $this->visit('/admin/user/new');
        $page->getByLabel('Email', ['exact' => true])->fill('password-limits@example.test');
        $page->evaluate('() => document.querySelector("form[name=User]").noValidate = true');
        $this->save($page);
        $page->getByText('This value should not be blank')->waitFor(['state' => 'visible', 'timeout' => 5000]);
        $this->assertPageContains('This value should not be blank');
        $page->getByLabel('New password', ['exact' => true])->fill(str_repeat('界', 11));
        $page->evaluate('() => document.querySelector("form[name=User]").noValidate = true');
        $this->save($page);
        $page->getByText('12 characters or more')->waitFor(['state' => 'visible', 'timeout' => 5000]);
        $this->assertPageContains('12 characters or more');
        // 1,400 Unicode characters fit the character limit but exceed 4,096 bytes.
        $oversized = str_repeat('界', 1400);
        $page->getByLabel('New password', ['exact' => true])->fill($oversized);
        $this->save($page);
        $page->getByText('4096 bytes or less')->waitFor(['state' => 'visible', 'timeout' => 5000]);
        $this->assertPageContains('4096 bytes or less');
        $page = $this->visit('/admin/user/'.$this->bobUser.'/edit');
        $originalHash = $this->storedPasswordHash('bob@example.test');
        $page->getByLabel('New password', ['exact' => true])->fill($oversized);
        $this->save($page);
        $page->getByText('4096 bytes or less')->waitFor(['state' => 'visible', 'timeout' => 5000]);
        $this->assertPageContains('4096 bytes or less');
        self::assertSame($originalHash, $this->storedPasswordHash('bob@example.test'));
        // Twelve characters, even when multibyte, satisfy the character minimum.
        $accepted = str_repeat('界', 12);
        $page = $this->visit('/admin/user/new');
        $page->getByLabel('Email', ['exact' => true])->fill('password-limits@example.test');
        $page->getByLabel('New password', ['exact' => true])->fill($accepted);
        $this->save($page);
        $page = $this->visit('/login');
        $this->submitLogin($page, 'password-limits@example.test', $accepted);
        $page->getByRole('heading', ['name' => 'Make sense of your money.', 'exact' => true])->waitFor();
    }
}
