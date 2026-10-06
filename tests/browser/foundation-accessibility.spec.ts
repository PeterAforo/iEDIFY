import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('preview is honest, accessible and keyboard navigable', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto('/');
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  await expect(page.getByText('Development preview', { exact: true })).toBeVisible();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('link', { name: 'Skip to content' })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.locator('#main-content')).toBeFocused();
  // Wait for scroll-reveal transitions to settle so axe never samples a
  // mid-fade opacity (transient opacity produces false contrast violations).
  await page.waitForFunction(() =>
    [...document.querySelectorAll('[data-reveal], [data-reveal-group] > *')].every((el) => {
      const opacity = Number.parseFloat(getComputedStyle(el).opacity);
      return opacity === 0 || opacity === 1;
    }),
  );
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
  expect(results.violations).toEqual([]);
  expect(errors).toEqual([]);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
});

test('private source paths are not served', async ({ request }) => {
  for (const path of ['/.env', '/.env.test', '/composer.json', '/storage/private/file.pdf', '/build/.vite/manifest.json', '/.runtime/mysql-init.sql']) {
    const response = await request.get(path);
    expect(response.status(), `Private path ${path} must return 404`).toBe(404);
    expect(response.headers()['cache-control']).toContain('no-store');
  }
});

test('essential preview content works without JavaScript', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false, reducedMotion: 'reduce' });
  const page = await context.newPage();
  await page.goto('http://127.0.0.1:8080/');
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  await expect(page.locator('#main-content').getByRole('link', { name: 'info@iedifyafrica.org' })).toHaveAttribute('href', 'mailto:info@iedifyafrica.org');
  await context.close();
});
