import { test, expect } from '@playwright/test';

/**
 * These tests don't use a real WordPress account -- they check the
 * captcha layer specifically (pass vs. block), not a full login. With a
 * valid token, WpLogin defers entirely to WordPress's own credential
 * check, so fake credentials correctly surface WP's own error, not
 * ours; that's the proof the captcha layer let the request through.
 */

async function waitForSolvedWidget(page: import('@playwright/test').Page) {
  await expect(page.locator('.nxc-widget__status')).toHaveText('Verified.', { timeout: 10_000 });
}

test.describe('WpLogin integration', () => {
  test('a valid token passes through to WordPress\'s own credential check', async ({ page }) => {
    await page.goto('/wp-login.php');
    await waitForSolvedWidget(page);

    await page.fill('#user_login', 'nxc-e2e-nonexistent-user');
    await page.fill('#user_pass', 'not-the-right-password');
    await page.click('#wp-submit');

    const error = page.locator('#login_error');
    await expect(error).toBeVisible();
    await expect(error).not.toContainText('Verification failed');
  });

  test('a missing token blocks the attempt regardless of credentials', async ({ page }) => {
    await page.goto('/wp-login.php');
    await waitForSolvedWidget(page);

    await page.evaluate(() => {
      const input = document.querySelector<HTMLInputElement>('input[name="nxc_token"]');
      if (input) {
        input.value = '';
      }
    });

    await page.fill('#user_login', 'nxc-e2e-nonexistent-user');
    await page.fill('#user_pass', 'not-the-right-password');
    await page.click('#wp-submit');

    await expect(page.locator('#login_error')).toContainText('Verification failed');
  });
});
