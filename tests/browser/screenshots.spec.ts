import { test } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const outDir = join(process.cwd(), 'docs', 'evidence', 'screenshots');
const pages = ['/', '/sign-in', '/programs', '/news', '/impact', '/faq', '/publications', '/about', '/team', '/contact'];

test('representative page screenshots', async ({ page }, testInfo) => {
  test.setTimeout(120000);
  mkdirSync(outDir, { recursive: true });
  for (const path of pages) {
    await page.goto(path);
    // Scroll through the page so scroll-reveal content has been triggered
    // before the full-page capture (real users see the same fade-in).
    await page.evaluate(async () => {
      await new Promise<void>((done) => {
        let y = 0;
        const step = () => {
          y += window.innerHeight;
          window.scrollTo({ top: y, behavior: 'instant' as ScrollBehavior });
          if (y < document.body.scrollHeight) setTimeout(step, 90);
          else { window.scrollTo({ top: 0, behavior: 'instant' as ScrollBehavior }); setTimeout(done, 500); }
        };
        step();
      });
    });
    await page.waitForLoadState('networkidle');
    const name = path === '/' ? 'home' : path.slice(1).replace(/\//g, '-');
    await page.screenshot({ path: join(outDir, `${testInfo.project.name}-${name}.png`), fullPage: true });
  }
});
