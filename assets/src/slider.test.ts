import { beforeEach, describe, expect, it } from 'vitest';
import { renderSlider, type SliderPayload } from './slider';

const PAYLOAD: SliderPayload = {
  mode: 'slider',
  image: 'data:image/png;base64,aaaa',
  piece: 'data:image/png;base64,bbbb',
  pieceY: 42,
  salt: 'abc123?expires=9999999999&form=login&v=1',
  signature: 'deadbeef',
};

function buildWidget(): HTMLElement {
  document.body.innerHTML = `
    <form>
      <div class="nxc-widget">
        <div class="nxc-widget__status"></div>
      </div>
    </form>
  `;
  return document.querySelector('.nxc-widget') as HTMLElement;
}

function piece(widget: HTMLElement): HTMLElement {
  return widget.querySelector('.nxc-widget__slider-piece') as HTMLElement;
}

function hiddenValue(widget: HTMLElement, name: string): string {
  return (widget.querySelector(`input[name="${name}"]`) as HTMLInputElement).value;
}

function pressKey(target: HTMLElement, key: string): void {
  target.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }));
}

describe('renderSlider', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  it('renders the background and piece with the payload data', () => {
    const widget = buildWidget();
    renderSlider(widget, PAYLOAD);

    const bg = widget.querySelector('.nxc-widget__slider-bg') as HTMLImageElement;
    expect(bg.src).toBe(PAYLOAD.image);
    expect(piece(widget).style.backgroundImage).toContain(PAYLOAD.piece);
    expect(piece(widget).style.top).toBe('42px');
  });

  it('starts the piece at the left edge with matching ARIA state', () => {
    const widget = buildWidget();
    renderSlider(widget, PAYLOAD);

    const p = piece(widget);
    expect(p.getAttribute('role')).toBe('slider');
    expect(p.getAttribute('aria-valuemin')).toBe('0');
    expect(p.getAttribute('aria-valuemax')).toBe('260'); // 300 - 40
    expect(p.getAttribute('aria-valuenow')).toBe('0');
    expect(hiddenValue(widget, 'nxc_slider_x')).toBe('0');
  });

  it('sets the salt and signature hidden inputs once from the payload', () => {
    const widget = buildWidget();
    renderSlider(widget, PAYLOAD);

    expect(hiddenValue(widget, 'nxc_slider_salt')).toBe(PAYLOAD.salt);
    expect(hiddenValue(widget, 'nxc_slider_signature')).toBe(PAYLOAD.signature);

    pressKey(piece(widget), 'ArrowRight');

    expect(hiddenValue(widget, 'nxc_slider_salt')).toBe(PAYLOAD.salt);
    expect(hiddenValue(widget, 'nxc_slider_signature')).toBe(PAYLOAD.signature);
  });

  it('moves right by the keyboard step on ArrowRight', () => {
    const widget = buildWidget();
    renderSlider(widget, PAYLOAD);

    pressKey(piece(widget), 'ArrowRight');

    expect(piece(widget).getAttribute('aria-valuenow')).toBe('5');
    expect(hiddenValue(widget, 'nxc_slider_x')).toBe('5');
  });

  it('moves left by the keyboard step on ArrowLeft, clamped at 0', () => {
    const widget = buildWidget();
    renderSlider(widget, PAYLOAD);

    pressKey(piece(widget), 'ArrowLeft');

    expect(hiddenValue(widget, 'nxc_slider_x')).toBe('0');
  });

  it('clamps at the right bound and jumps there on End', () => {
    const widget = buildWidget();
    renderSlider(widget, PAYLOAD);

    pressKey(piece(widget), 'End');

    expect(hiddenValue(widget, 'nxc_slider_x')).toBe('260');

    pressKey(piece(widget), 'ArrowRight');

    expect(hiddenValue(widget, 'nxc_slider_x')).toBe('260');
  });

  it('jumps back to 0 on Home', () => {
    const widget = buildWidget();
    renderSlider(widget, PAYLOAD);

    pressKey(piece(widget), 'End');
    pressKey(piece(widget), 'Home');

    expect(hiddenValue(widget, 'nxc_slider_x')).toBe('0');
  });

  it('grows the trace with each keyboard move', () => {
    const widget = buildWidget();
    renderSlider(widget, PAYLOAD);

    const traceAfterRender = JSON.parse(hiddenValue(widget, 'nxc_slider_trace'));
    expect(traceAfterRender).toHaveLength(1);

    pressKey(piece(widget), 'ArrowRight');
    pressKey(piece(widget), 'ArrowRight');

    const trace = JSON.parse(hiddenValue(widget, 'nxc_slider_trace'));
    expect(trace).toHaveLength(3);
    expect(trace.map((point: { x: number }) => point.x)).toEqual([0, 5, 10]);
    trace.forEach((point: { x: number; t: number }) => {
      expect(typeof point.t).toBe('number');
    });
  });

  it('replaces a previous render instead of duplicating markup or inputs', () => {
    const widget = buildWidget();
    renderSlider(widget, PAYLOAD);
    pressKey(piece(widget), 'ArrowRight');

    renderSlider(widget, { ...PAYLOAD, salt: 'fresh-salt?expires=9999999999&form=login&v=1' });

    expect(widget.querySelectorAll('.nxc-widget__slider')).toHaveLength(1);
    expect(widget.querySelectorAll('input[name="nxc_slider_x"]')).toHaveLength(1);
    expect(hiddenValue(widget, 'nxc_slider_salt')).toBe('fresh-salt?expires=9999999999&form=login&v=1');
    expect(hiddenValue(widget, 'nxc_slider_x')).toBe('0');
  });

  it('sets neutral status guidance, never a "Verified" claim', () => {
    const widget = buildWidget();
    renderSlider(widget, PAYLOAD);

    const status = widget.querySelector('.nxc-widget__status') as HTMLElement;
    expect(status.textContent).not.toMatch(/verified/i);
  });
});
