import { test, expect, type Page } from '@playwright/test';

/**
 * Forces mode=slider on the challenge fetch regardless of the live
 * site's actual risk score -- RiskScorer's escalation is unit-tested
 * (RiskScorerTest) and separately verified live against the real
 * rate-limit bucket; this spec is about proving the frontend widget
 * itself (render, keyboard operability, drag, submission plumbing),
 * which needs a deterministic mode rather than one at the mercy of
 * other tests' shared per-IP rate-limit state (this config runs
 * fullyParallel).
 */
async function forceSliderMode(page: Page): Promise<void> {
  await page.route('**/wp-json/nexcaptcha/v1/challenge*', (route) => {
    const url = new URL(route.request().url());
    url.searchParams.set('mode', 'slider');
    void route.continue({ url: url.toString() });
  });
}

/**
 * The gap position is never sent to the client (see
 * Challenge\SliderChallenge's own docblock) -- it's only visible as a
 * darkened, white-bordered square drawn directly onto the background
 * image, the same way a sighted human visitor would find it. Reading
 * that border's pixels in-browser via Canvas is the legitimate way to
 * locate it, not a bypass: it's exactly the "defeat it visually" bar a
 * real solver (human or bot) has to clear, with no secret extracted.
 */
async function findGapX(page: Page): Promise<number> {
  return page.evaluate(() => {
    return new Promise<number>((resolve, reject) => {
      const bg = document.querySelector<HTMLImageElement>('.nxc-widget__slider-bg');
      const piece = document.querySelector<HTMLElement>('.nxc-widget__slider-piece');
      if (!bg || !piece) {
        reject(new Error('Slider markup not found.'));
        return;
      }
      const pieceY = parseInt(piece.style.top, 10);
      const img = new Image();
      img.onload = () => {
        const canvas = document.createElement('canvas');
        canvas.width = img.naturalWidth;
        canvas.height = img.naturalHeight;
        const ctx = canvas.getContext('2d');
        if (!ctx) {
          reject(new Error('2D canvas context unavailable.'));
          return;
        }
        ctx.drawImage(img, 0, 0);
        const row = ctx.getImageData(0, pieceY, img.naturalWidth, 1).data;
        for (let x = 0; x < img.naturalWidth; x++) {
          const [r, g, b] = [row[x * 4], row[x * 4 + 1], row[x * 4 + 2]];
          if (r === 255 && g === 255 && b === 255) {
            resolve(x);
            return;
          }
        }
        reject(new Error('No white border pixel found in the gap row.'));
      };
      img.onerror = () => reject(new Error('Background image failed to load.'));
      img.src = bg.src;
    });
  });
}

async function waitForSliderWidget(page: Page) {
  await expect(page.locator('.nxc-widget__slider-piece')).toBeVisible({ timeout: 10_000 });
}

async function submitWithWrongCredentials(page: Page) {
  await page.fill('#user_login', 'nxc-e2e-nonexistent-user');
  await page.fill('#user_pass', 'not-the-right-password');
  await page.click('#wp-submit');
}

test.describe('Slider widget', () => {
  test('renders as a keyboard-operable ARIA slider', async ({ page }) => {
    await forceSliderMode(page);
    await page.goto('/wp-login.php');
    await waitForSliderWidget(page);

    const piece = page.locator('.nxc-widget__slider-piece');
    await expect(piece).toHaveAttribute('role', 'slider');
    await expect(piece).toHaveAttribute('aria-valuenow', '0');
    await expect(page.locator('input[name="nxc_slider_x"]')).toHaveValue('0');

    await piece.focus();
    await page.keyboard.press('ArrowRight');

    await expect(piece).toHaveAttribute('aria-valuenow', '5');
    await expect(page.locator('input[name="nxc_slider_x"]')).toHaveValue('5');
  });

  test('a correct keyboard-only solve passes through to WordPress\'s own credential check', async ({ page }) => {
    await forceSliderMode(page);
    await page.goto('/wp-login.php');
    await waitForSliderWidget(page);

    const gapX = await findGapX(page);
    const piece = page.locator('.nxc-widget__slider-piece');
    await piece.focus();

    for (let moved = 0; moved < gapX; moved += 5) {
      await page.keyboard.press('ArrowRight');
    }

    await submitWithWrongCredentials(page);

    const error = page.locator('#login_error');
    await expect(error).toBeVisible();
    await expect(error).not.toContainText('Verification failed');
  });

  test('a correct mouse drag solve passes through to WordPress\'s own credential check', async ({ page }) => {
    await forceSliderMode(page);
    await page.goto('/wp-login.php');
    await waitForSliderWidget(page);

    const gapX = await findGapX(page);
    const container = page.locator('.nxc-widget__slider');
    const piece = page.locator('.nxc-widget__slider-piece');
    const containerBox = await container.boundingBox();
    const pieceBox = await piece.boundingBox();
    if (!containerBox || !pieceBox) {
      throw new Error('Slider widget did not lay out.');
    }

    await page.mouse.move(pieceBox.x + pieceBox.width / 2, pieceBox.y + pieceBox.height / 2);
    await page.mouse.down();
    await page.mouse.move(containerBox.x + gapX + pieceBox.width / 2, pieceBox.y + pieceBox.height / 2, { steps: 10 });
    await page.mouse.up();

    await submitWithWrongCredentials(page);

    const error = page.locator('#login_error');
    await expect(error).toBeVisible();
    await expect(error).not.toContainText('Verification failed');
  });

  test('a position outside tolerance fails verification', async ({ page }) => {
    await forceSliderMode(page);
    await page.goto('/wp-login.php');
    await waitForSliderWidget(page);

    const gapX = await findGapX(page);
    const piece = page.locator('.nxc-widget__slider-piece');
    await piece.focus();

    const target = Math.min(gapX + 40, 260); // Well outside SliderChallenge::TOLERANCE (5).
    for (let moved = 0; moved < target; moved += 5) {
      await page.keyboard.press('ArrowRight');
    }

    await submitWithWrongCredentials(page);

    await expect(page.locator('#login_error')).toContainText('Verification failed');
  });
});
