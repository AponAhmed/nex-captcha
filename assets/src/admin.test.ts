import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fetchLogs, paginationParams, saveSettings, showToast } from './admin';

function buildSettingsFixture(): { form: HTMLFormElement; button: HTMLButtonElement; label: HTMLElement } {
  document.body.innerHTML = `
    <form id="nxc-settings-form" data-nonce="test-nonce">
      <input type="text" name="nxc_settings[difficulty]" value="balanced" />
      <button type="submit" class="button button-primary">
        <span class="nxc-btn-label">Save Changes</span>
      </button>
    </form>
    <div id="nxc-toaster"></div>
  `;

  return {
    form: document.getElementById('nxc-settings-form') as HTMLFormElement,
    button: document.querySelector('button[type="submit"]') as HTMLButtonElement,
    label: document.querySelector('.nxc-btn-label') as HTMLElement,
  };
}

function buildDrawerFixture(): void {
  document.body.innerHTML = `
    <div id="nxc-log-drawer" data-nonce="drawer-nonce">
      <div class="nxc-drawer__filters">
        <button type="button" class="nxc-filter is-active" data-result=""></button>
        <button type="button" class="nxc-filter" data-result="passed"></button>
      </div>
      <div class="nxc-drawer__content">original rows</div>
    </div>
  `;
}

describe('saveSettings', () => {
  beforeEach(() => {
    vi.stubGlobal('ajaxurl', '/wp-admin/admin-ajax.php');
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it('shows a success toast and restores the button on a successful save', async () => {
    const { form, button, label } = buildSettingsFixture();
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({ json: () => Promise.resolve({ success: true, data: { message: 'Settings saved.' } }) }),
    );

    const event = { currentTarget: form, submitter: button, preventDefault: vi.fn() } as unknown as SubmitEvent;
    await saveSettings(event);

    expect(event.preventDefault).toHaveBeenCalled();
    expect(button.disabled).toBe(false);
    expect(label.textContent).toBe('Save Changes');
    expect(document.querySelector('.nxc-toast--success')?.textContent).toContain('Settings saved.');
  });

  it('shows an error toast when the server rejects the save', async () => {
    const { form, button } = buildSettingsFixture();
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({ json: () => Promise.resolve({ success: false, data: { message: 'Nope.' } }) }),
    );

    const event = { currentTarget: form, submitter: button, preventDefault: vi.fn() } as unknown as SubmitEvent;
    await saveSettings(event);

    expect(document.querySelector('.nxc-toast--error')?.textContent).toContain('Nope.');
  });

  it('shows an error toast when the request itself fails', async () => {
    const { form, button } = buildSettingsFixture();
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('network down')));

    const event = { currentTarget: form, submitter: button, preventDefault: vi.fn() } as unknown as SubmitEvent;
    await saveSettings(event);

    expect(document.querySelector('.nxc-toast--error')).not.toBeNull();
    expect(button.disabled).toBe(false);
  });
});

describe('fetchLogs', () => {
  beforeEach(() => {
    vi.stubGlobal('ajaxurl', '/wp-admin/admin-ajax.php');
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it('posts the requested result filter and swaps the drawer content', async () => {
    buildDrawerFixture();
    const fetchMock = vi
      .fn()
      .mockResolvedValue({
        json: () => Promise.resolve({ success: true, data: { html: '<table><tbody><tr><td>filtered rows</td></tr></tbody></table>' } }),
      });
    vi.stubGlobal('fetch', fetchMock);

    await fetchLogs({ result: 'passed', paged: 1 });

    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe('/wp-admin/admin-ajax.php');
    const body = (init as RequestInit).body as URLSearchParams;
    expect(body.get('action')).toBe('nxc_filter_logs');
    expect(body.get('nonce')).toBe('drawer-nonce');
    expect(body.get('nxc_result')).toBe('passed');

    expect(document.querySelector('.nxc-drawer__content')?.textContent).toContain('filtered rows');
    expect(document.querySelector('.nxc-filter[data-result="passed"]')?.classList.contains('is-active')).toBe(true);
    expect(document.querySelector('.nxc-filter[data-result=""]')?.classList.contains('is-active')).toBe(false);
  });

  it('leaves existing rows in place when the request fails', async () => {
    buildDrawerFixture();
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('network down')));

    await fetchLogs({ result: 'failed', paged: 1 });

    expect(document.querySelector('.nxc-drawer__content')?.innerHTML).toBe('original rows');
    expect(document.querySelector('.nxc-drawer__content')?.classList.contains('is-loading')).toBe(false);
  });
});

describe('paginationParams', () => {
  it('reads paged/result/form off a pagination link href', () => {
    const params = paginationParams('https://example.test/wp-admin/options-general.php?page=nex-captcha&view=logs&paged=3&nxc_result=blocked&nxc_form=login');

    expect(params).toEqual({ paged: 3, result: 'blocked', form: 'login' });
  });

  it('defaults to page 1 with no filters when nothing is present', () => {
    const params = paginationParams('https://example.test/wp-admin/options-general.php?page=nex-captcha');

    expect(params).toEqual({ paged: 1, result: '', form: '' });
  });
});

describe('showToast', () => {
  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('does nothing when the toaster container is missing', () => {
    document.body.innerHTML = '';
    expect(() => showToast('success', 'hi')).not.toThrow();
  });
});
