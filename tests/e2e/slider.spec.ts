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

interface Gap {
  x: number;
  y: number;
}

/**
 * The gap position is never sent to the client (see
 * Challenge\SliderChallenge's own docblock) -- it's only visible as a
 * darkened, white-bordered square drawn directly onto the background
 * image, the same way a sighted human visitor would find it. Reading
 * that border's pixels in-browser via Canvas is the legitimate way to
 * locate it, not a bypass: it's exactly the "defeat it visually" bar a
 * real solver (human or bot) has to clear, with no secret extracted.
 * Neither X nor Y is known ahead of time now (a real 2D puzzle), so
 * this scans the whole image for the border's bounding box.
 */
async function findGap(page: Page): Promise<Gap> {
  return page.evaluate(() => {
    return new Promise<Gap>((resolve, reject) => {
      const bg = document.querySelector<HTMLImageElement>('.nxc-widget__slider-bg');
      if (!bg) {
        reject(new Error('Slider markup not found.'));
        return;
      }
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
        const data = ctx.getImageData(0, 0, img.naturalWidth, img.naturalHeight).data;

        let minX = Infinity;
        let minY = Infinity;
        for (let y = 0; y < img.naturalHeight; y++) {
          for (let x = 0; x < img.naturalWidth; x++) {
            const i = (y * img.naturalWidth + x) * 4;
            if (data[i] === 255 && data[i + 1] === 255 && data[i + 2] === 255) {
              minX = Math.min(minX, x);
              minY = Math.min(minY, y);
            }
          }
        }

        if (!Number.isFinite(minX) || !Number.isFinite(minY)) {
          reject(new Error('No white border pixel found in the image.'));
          return;
        }
        resolve({ x: minX, y: minY });
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
  test('renders as a keyboard-operable ARIA slider, movable on both axes', async ({ page }) => {
    await forceSliderMode(page);
    await page.goto('/wp-login.php');
    await waitForSliderWidget(page);

    const piece = page.locator('.nxc-widget__slider-piece');
    await expect(piece).toHaveAttribute('role', 'slider');
    await expect(piece).toHaveAttribute('aria-valuetext', 'Position 0, 0');
    await expect(page.locator('input[name="nxc_slider_x"]')).toHaveValue('0');
    await expect(page.locator('input[name="nxc_slider_y"]')).toHaveValue('0');

    await piece.focus();
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('ArrowDown');

    await expect(piece).toHaveAttribute('aria-valuetext', 'Position 5, 5');
    await expect(page.locator('input[name="nxc_slider_x"]')).toHaveValue('5');
    await expect(page.locator('input[name="nxc_slider_y"]')).toHaveValue('5');
  });

  test('a correct keyboard-only solve passes through to WordPress\'s own credential check', async ({ page }) => {
    await forceSliderMode(page);
    await page.goto('/wp-login.php');
    await waitForSliderWidget(page);

    const gap = await findGap(page);
    const piece = page.locator('.nxc-widget__slider-piece');
    await piece.focus();

    for (let moved = 0; moved < gap.x; moved += 5) {
      await page.keyboard.press('ArrowRight');
    }
    for (let moved = 0; moved < gap.y; moved += 5) {
      await page.keyboard.press('ArrowDown');
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

    const gap = await findGap(page);
    const frame = page.locator('.nxc-widget__slider-frame');
    const piece = page.locator('.nxc-widget__slider-piece');
    const frameBox = await frame.boundingBox();
    const pieceBox = await piece.boundingBox();
    if (!frameBox || !pieceBox) {
      throw new Error('Slider widget did not lay out.');
    }

    // gap.x/gap.y are in native 0-300x0-150 units; the frame can render
    // smaller than that (e.g. wp-login.php's own form is ~270px wide),
    // so convert via the actual render scale -- same conversion
    // slider.ts's own drag handler does.
    const scaleX = frameBox.width / 300;
    const scaleY = frameBox.height / 150;
    const targetScreenX = frameBox.x + gap.x * scaleX + pieceBox.width / 2;
    const targetScreenY = frameBox.y + gap.y * scaleY + pieceBox.height / 2;

    await page.mouse.move(pieceBox.x + pieceBox.width / 2, pieceBox.y + pieceBox.height / 2);
    await page.mouse.down();
    await page.mouse.move(targetScreenX, targetScreenY, { steps: 10 });
    await page.mouse.up();

    await submitWithWrongCredentials(page);

    const error = page.locator('#login_error');
    await expect(error).toBeVisible();
    await expect(error).not.toContainText('Verification failed');
  });

  test('a correct X but wrong Y fails verification (Y genuinely matters)', async ({ page }) => {
    await forceSliderMode(page);
    await page.goto('/wp-login.php');
    await waitForSliderWidget(page);

    const gap = await findGap(page);
    const piece = page.locator('.nxc-widget__slider-piece');
    await piece.focus();

    for (let moved = 0; moved < gap.x; moved += 5) {
      await page.keyboard.press('ArrowRight');
    }
    // Deliberately well outside SliderChallenge::TOLERANCE (5) on Y only.
    const targetY = Math.min(gap.y + 40, 110);
    for (let moved = 0; moved < targetY; moved += 5) {
      await page.keyboard.press('ArrowDown');
    }

    await submitWithWrongCredentials(page);

    await expect(page.locator('#login_error')).toContainText('Verification failed');
  });

  test('a position outside tolerance on X fails verification', async ({ page }) => {
    await forceSliderMode(page);
    await page.goto('/wp-login.php');
    await waitForSliderWidget(page);

    const gap = await findGap(page);
    const piece = page.locator('.nxc-widget__slider-piece');
    await piece.focus();

    const targetX = Math.min(gap.x + 40, 260); // Well outside SliderChallenge::TOLERANCE (5).
    for (let moved = 0; moved < targetX; moved += 5) {
      await page.keyboard.press('ArrowRight');
    }
    for (let moved = 0; moved < gap.y; moved += 5) {
      await page.keyboard.press('ArrowDown');
    }

    await submitWithWrongCredentials(page);

    await expect(page.locator('#login_error')).toContainText('Verification failed');
  });

  test('the accessible-alternative link switches to a math question and a correct answer passes', async ({ page }) => {
    // Only the FIRST challenge fetch is forced to slider -- the click
    // handler's own re-fetch explicitly requests mode=math, and a
    // blanket forceSliderMode() would clobber that back to slider,
    // defeating the exact thing this test is proving.
    let forced = false;
    await page.route('**/wp-json/nexcaptcha/v1/challenge*', (route) => {
      if (forced) {
        void route.continue();
        return;
      }
      forced = true;
      const url = new URL(route.request().url());
      url.searchParams.set('mode', 'slider');
      void route.continue({ url: url.toString() });
    });

    await page.goto('/wp-login.php');
    await waitForSliderWidget(page);

    await page.click('.nxc-widget__slider-alt');

    const answer = page.locator('input[name="nxc_math_answer"]');
    await expect(answer).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('.nxc-widget__slider-piece')).toHaveCount(0);

    // The question is only in the image's alt text now (questionImage
    // is the visible, stylized rendering) -- see math.ts's own docblock.
    const image = page.locator('.nxc-widget__math-js-image');
    await expect(image).toBeVisible();
    const alt = await image.getAttribute('alt');
    const match = alt?.match(/(\d+)\s*\+\s*(\d+)/);
    if (!match) {
      throw new Error(`Could not parse a question out of alt text: ${alt}`);
    }
    const sum = parseInt(match[1], 10) + parseInt(match[2], 10);

    await answer.fill(String(sum));

    // nxc_ts is set once at page render and isn't reset by switching
    // modes client-side -- this flow has fewer interaction steps than
    // the drag-based tests above, so without a pause it can complete
    // fast enough to trip Signals\TimeTrap's own 2s floor, same as a
    // real visitor moving unusually fast would.
    await page.waitForTimeout(2200);
    await submitWithWrongCredentials(page);

    const error = page.locator('#login_error');
    await expect(error).toBeVisible();
    await expect(error).not.toContainText('Verification failed');
  });
});
