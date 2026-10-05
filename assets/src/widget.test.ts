import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { hasWebCrypto, interactionInput, setButtonsDisabled, trackInteraction } from './widget';

function buildFixture(): { widget: HTMLElement; form: HTMLFormElement } {
  document.body.innerHTML = `
    <form>
      <input type="text" name="username" />
      <div class="nxc-widget">
        <input type="hidden" name="nxc_interacted" value="0" />
      </div>
    </form>
  `;

  return {
    form: document.querySelector('form') as HTMLFormElement,
    widget: document.querySelector('.nxc-widget') as HTMLElement,
  };
}

describe('trackInteraction', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  it('leaves nxc_interacted at 0 with no interaction', () => {
    const { widget } = buildFixture();
    trackInteraction(widget);

    expect(interactionInput(widget)?.value).toBe('0');
  });

  it('sets nxc_interacted to 1 on a pointerdown anywhere in the form', () => {
    const { widget, form } = buildFixture();
    trackInteraction(widget);

    form.dispatchEvent(new Event('pointerdown', { bubbles: true }));

    expect(interactionInput(widget)?.value).toBe('1');
  });

  it('sets nxc_interacted to 1 on a focusin (covers keyboard/Tab navigation too)', () => {
    const { widget, form } = buildFixture();
    trackInteraction(widget);

    const username = form.querySelector('input[name="username"]') as HTMLInputElement;
    username.dispatchEvent(new FocusEvent('focusin', { bubbles: true }));

    expect(interactionInput(widget)?.value).toBe('1');
  });

  it('sets nxc_interacted to 1 on an input event', () => {
    const { widget, form } = buildFixture();
    trackInteraction(widget);

    const username = form.querySelector('input[name="username"]') as HTMLInputElement;
    username.dispatchEvent(new Event('input', { bubbles: true }));

    expect(interactionInput(widget)?.value).toBe('1');
  });

  it('does nothing if the widget has no enclosing form', () => {
    document.body.innerHTML = '<div class="nxc-widget"><input type="hidden" name="nxc_interacted" value="0" /></div>';
    const widget = document.querySelector('.nxc-widget') as HTMLElement;

    expect(() => trackInteraction(widget)).not.toThrow();
  });
});

describe('setButtonsDisabled', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  it('disables every submit control in the enclosing form', () => {
    document.body.innerHTML = `
      <form>
        <div class="nxc-widget"></div>
        <button type="submit">Log In</button>
        <input type="submit" value="Also submit" />
        <button type="button">Not a submit</button>
      </form>
    `;
    const widget = document.querySelector('.nxc-widget') as HTMLElement;

    setButtonsDisabled(widget, true);

    const [submitButton, submitInput, plainButton] = document.querySelectorAll('button, input');
    expect((submitButton as HTMLButtonElement).disabled).toBe(true);
    expect((submitInput as HTMLInputElement).disabled).toBe(true);
    expect((plainButton as HTMLButtonElement).disabled).toBe(false);
  });

  it('re-enables after being disabled', () => {
    document.body.innerHTML = `
      <form>
        <div class="nxc-widget"></div>
        <button type="submit">Log In</button>
      </form>
    `;
    const widget = document.querySelector('.nxc-widget') as HTMLElement;
    const button = document.querySelector('button') as HTMLButtonElement;

    setButtonsDisabled(widget, true);
    expect(button.disabled).toBe(true);

    setButtonsDisabled(widget, false);
    expect(button.disabled).toBe(false);
  });

  it('does nothing if the widget has no enclosing form', () => {
    document.body.innerHTML = '<div class="nxc-widget"></div>';
    const widget = document.querySelector('.nxc-widget') as HTMLElement;

    expect(() => setButtonsDisabled(widget, true)).not.toThrow();
  });
});

describe('wpcf7submit re-arm', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('re-disables the submit button, so a second CF7 send on the same page waits for a fresh token', () => {
    // Never resolves -- this test only checks the synchronous state
    // solveWidget() sets before its first await, not the fetch outcome.
    vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})));

    document.body.innerHTML = `
      <form class="wpcf7-form">
        <div class="nxc-widget" data-form="cf7:1" data-rest-url="https://example.test/wp-json/nexcaptcha/v1/">
          <input type="hidden" name="nxc_token" value="stale-token-from-first-send" />
        </div>
        <button type="submit">Send</button>
      </form>
    `;
    const form = document.querySelector('form') as HTMLFormElement;
    const button = document.querySelector('button') as HTMLButtonElement;
    button.disabled = false;

    form.dispatchEvent(new Event('wpcf7submit', { bubbles: true }));

    expect(button.disabled).toBe(true);
  });
});

describe('hasWebCrypto', () => {
  const originalCrypto = globalThis.crypto;

  afterEach(() => {
    vi.stubGlobal('crypto', originalCrypto);
  });

  it('is true in jsdom\'s default environment (crypto.subtle present)', () => {
    expect(hasWebCrypto()).toBe(true);
  });

  it('is false when crypto.subtle is missing, as in a non-secure context', () => {
    vi.stubGlobal('crypto', {});

    expect(hasWebCrypto()).toBe(false);
  });

  it('is false when crypto itself is undefined', () => {
    vi.stubGlobal('crypto', undefined);

    expect(hasWebCrypto()).toBe(false);
  });
});
