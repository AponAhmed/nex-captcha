import { beforeEach, describe, expect, it, vi } from 'vitest';
import { renderSlider, type SliderPayload } from './slider';

const PAYLOAD: SliderPayload = {
  mode: 'slider',
  image: 'data:image/png;base64,aaaa',
  piece: 'data:image/png;base64,bbbb',
  salt: 'abc123?expires=9999999999&form=login&v=1',
  signature: 'deadbeef',
};

/** Most tests don't care about the alternative-link callback. */
function render(widget: HTMLElement, payload: SliderPayload, onRequestAlternative: () => void = () => {}): void {
  renderSlider(widget, payload, onRequestAlternative);
}

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
    render(widget, PAYLOAD);

    const bg = widget.querySelector('.nxc-widget__slider-bg') as HTMLImageElement;
    expect(bg.src).toBe(PAYLOAD.image);
    expect(piece(widget).style.backgroundImage).toContain(PAYLOAD.piece);
  });

  it('sizes the piece as a percentage of the native dimensions, not fixed pixels', () => {
    const widget = buildWidget();
    render(widget, PAYLOAD);

    const p = piece(widget);
    expect(p.style.width).toBe(`${(40 / 300) * 100}%`);
    expect(p.style.height).toBe(`${(40 / 150) * 100}%`);
  });

  it('starts the piece at the top-left corner with matching ARIA state', () => {
    const widget = buildWidget();
    render(widget, PAYLOAD);

    const p = piece(widget);
    expect(p.getAttribute('role')).toBe('slider');
    expect(p.getAttribute('aria-valuetext')).toBe('Position 0, 0');
    expect(p.style.left).toBe('0%');
    expect(p.style.top).toBe('0%');
    expect(hiddenValue(widget, 'nxc_slider_x')).toBe('0');
    expect(hiddenValue(widget, 'nxc_slider_y')).toBe('0');
  });

  it('sets the salt and signature hidden inputs once from the payload', () => {
    const widget = buildWidget();
    render(widget, PAYLOAD);

    expect(hiddenValue(widget, 'nxc_slider_salt')).toBe(PAYLOAD.salt);
    expect(hiddenValue(widget, 'nxc_slider_signature')).toBe(PAYLOAD.signature);

    pressKey(piece(widget), 'ArrowRight');

    expect(hiddenValue(widget, 'nxc_slider_salt')).toBe(PAYLOAD.salt);
    expect(hiddenValue(widget, 'nxc_slider_signature')).toBe(PAYLOAD.signature);
  });

  it('moves right by the keyboard step on ArrowRight', () => {
    const widget = buildWidget();
    render(widget, PAYLOAD);

    pressKey(piece(widget), 'ArrowRight');

    expect(hiddenValue(widget, 'nxc_slider_x')).toBe('5');
    expect(hiddenValue(widget, 'nxc_slider_y')).toBe('0');
    expect(piece(widget).getAttribute('aria-valuetext')).toBe('Position 5, 0');
  });

  it('moves left by the keyboard step on ArrowLeft, clamped at 0', () => {
    const widget = buildWidget();
    render(widget, PAYLOAD);

    pressKey(piece(widget), 'ArrowLeft');

    expect(hiddenValue(widget, 'nxc_slider_x')).toBe('0');
  });

  it('moves down by the keyboard step on ArrowDown', () => {
    const widget = buildWidget();
    render(widget, PAYLOAD);

    pressKey(piece(widget), 'ArrowDown');

    expect(hiddenValue(widget, 'nxc_slider_y')).toBe('5');
    expect(hiddenValue(widget, 'nxc_slider_x')).toBe('0');
  });

  it('moves up by the keyboard step on ArrowUp, clamped at 0', () => {
    const widget = buildWidget();
    render(widget, PAYLOAD);

    pressKey(piece(widget), 'ArrowUp');

    expect(hiddenValue(widget, 'nxc_slider_y')).toBe('0');
  });

  it('clamps at the bottom-right bound and jumps there on End', () => {
    const widget = buildWidget();
    render(widget, PAYLOAD);

    pressKey(piece(widget), 'End');

    expect(hiddenValue(widget, 'nxc_slider_x')).toBe('260'); // 300 - 40
    expect(hiddenValue(widget, 'nxc_slider_y')).toBe('110'); // 150 - 40

    pressKey(piece(widget), 'ArrowRight');
    pressKey(piece(widget), 'ArrowDown');

    expect(hiddenValue(widget, 'nxc_slider_x')).toBe('260');
    expect(hiddenValue(widget, 'nxc_slider_y')).toBe('110');
  });

  it('jumps back to the top-left corner on Home', () => {
    const widget = buildWidget();
    render(widget, PAYLOAD);

    pressKey(piece(widget), 'End');
    pressKey(piece(widget), 'Home');

    expect(hiddenValue(widget, 'nxc_slider_x')).toBe('0');
    expect(hiddenValue(widget, 'nxc_slider_y')).toBe('0');
  });

  it('grows the trace with each keyboard move, tracking both axes', () => {
    const widget = buildWidget();
    render(widget, PAYLOAD);

    const traceAfterRender = JSON.parse(hiddenValue(widget, 'nxc_slider_trace'));
    expect(traceAfterRender).toHaveLength(1);

    pressKey(piece(widget), 'ArrowRight');
    pressKey(piece(widget), 'ArrowDown');

    const trace = JSON.parse(hiddenValue(widget, 'nxc_slider_trace'));
    expect(trace).toHaveLength(3);
    expect(trace.map((point: { x: number; y: number }) => [point.x, point.y])).toEqual([
      [0, 0],
      [5, 0],
      [5, 5],
    ]);
    trace.forEach((point: { x: number; y: number; t: number }) => {
      expect(typeof point.t).toBe('number');
    });
  });

  it('replaces a previous render instead of duplicating markup or inputs', () => {
    const widget = buildWidget();
    render(widget, PAYLOAD);
    pressKey(piece(widget), 'ArrowRight');

    render(widget, { ...PAYLOAD, salt: 'fresh-salt?expires=9999999999&form=login&v=1' });

    expect(widget.querySelectorAll('.nxc-widget__slider')).toHaveLength(1);
    expect(widget.querySelectorAll('input[name="nxc_slider_x"]')).toHaveLength(1);
    expect(widget.querySelectorAll('input[name="nxc_slider_y"]')).toHaveLength(1);
    expect(hiddenValue(widget, 'nxc_slider_salt')).toBe('fresh-salt?expires=9999999999&form=login&v=1');
    expect(hiddenValue(widget, 'nxc_slider_x')).toBe('0');
  });

  it('sets neutral status guidance, never a "Verified" claim', () => {
    const widget = buildWidget();
    render(widget, PAYLOAD);

    const status = widget.querySelector('.nxc-widget__status') as HTMLElement;
    expect(status.textContent).not.toMatch(/verified/i);
  });

  it('calls onRequestAlternative when the accessible-alternative button is clicked', () => {
    const widget = buildWidget();
    const onRequestAlternative = vi.fn();
    renderSlider(widget, PAYLOAD, onRequestAlternative);

    const button = widget.querySelector('.nxc-widget__slider-alt') as HTMLButtonElement;
    expect(button.type).toBe('button');
    button.click();

    expect(onRequestAlternative).toHaveBeenCalledOnce();
  });

  it('removes the alternative button on re-render, along with the rest of the slider markup', () => {
    const widget = buildWidget();
    render(widget, PAYLOAD);
    render(widget, PAYLOAD);

    expect(widget.querySelectorAll('.nxc-widget__slider-alt')).toHaveLength(1);
  });
});
