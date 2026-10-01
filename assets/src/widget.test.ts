import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { hasWebCrypto, interactionInput, trackInteraction } from './widget';

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
