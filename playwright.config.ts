import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: true,
  reporter: 'list',
  use: {
    baseURL: process.env.WP_BASE_URL ?? 'http://localhost:8888',
    trace: 'retain-on-failure',
  },
});
