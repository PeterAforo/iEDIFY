import { test } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const outDir = join(process.cwd(), 'docs', 'evidence', 'screenshots');
const pages = ['/', '/sign-in', '/programs', '/news', '/impact', '/faq', '/publications'];

test('representative page screenshots', async ({ page }, testInfo) => {
  mkdirSync(outDir, { recursive: true });
  for (const path of pages) {
    await page.goto(path);
    await page.waitForLoadState('networkidle');
    const name = path === '/' ? 'home' : path.slice(1).replace(/\//g, '-');
    await page.screenshot({ path: join(outDir, `${testInfo.project.name}-${name}.png`), fullPage: true });
  }
});
