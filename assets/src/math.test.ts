import { beforeEach, describe, expect, it } from 'vitest';
import { renderMath, type MathPayload } from './math';

const PAYLOAD: MathPayload = {
  mode: 'math',
  question: '7 + 3',
  questionImage: 'data:image/png;base64,aaaa',
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

function hiddenValue(widget: HTMLElement, name: string): string {
  return (widget.querySelector(`input[name="${name}"]`) as HTMLInputElement).value;
}

function questionImage(widget: HTMLElement): HTMLImageElement {
  return widget.querySelector('.nxc-widget__math-js-image') as HTMLImageElement;
}

describe('renderMath', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  it('renders the question image from the payload', () => {
    const widget = buildWidget();
    renderMath(widget, PAYLOAD);

    expect(questionImage(widget).src).toBe(PAYLOAD.questionImage);
  });

  it('carries the real question as alt text, for accessibility despite the stylized image', () => {
    const widget = buildWidget();
    renderMath(widget, PAYLOAD);

    expect(questionImage(widget).alt).toContain('7 + 3');
  });

  it('renders a visible answer field named nxc_math_answer', () => {
    const widget = buildWidget();
    renderMath(widget, PAYLOAD);

    const answer = widget.querySelector('input[name="nxc_math_answer"]') as HTMLInputElement;
    expect(answer).not.toBeNull();
    expect(answer.type).toBe('text');
    expect(answer.hidden).toBe(false);
  });

  it('associates the label with the answer field', () => {
    const widget = buildWidget();
    renderMath(widget, PAYLOAD);

    const label = widget.querySelector('.nxc-widget__math-js label') as HTMLLabelElement;
    const answer = widget.querySelector('input[name="nxc_math_answer"]') as HTMLInputElement;
    expect(label.htmlFor).toBe(answer.id);
  });

  it('sets the salt and signature hidden inputs once from the payload', () => {
    const widget = buildWidget();
    renderMath(widget, PAYLOAD);

    expect(hiddenValue(widget, 'nxc_math_salt')).toBe(PAYLOAD.salt);
    expect(hiddenValue(widget, 'nxc_math_signature')).toBe(PAYLOAD.signature);
  });

  it('sets neutral status guidance, never a "Verified" claim', () => {
    const widget = buildWidget();
    renderMath(widget, PAYLOAD);

    const status = widget.querySelector('.nxc-widget__status') as HTMLElement;
    expect(status.textContent).not.toMatch(/verified/i);
  });

  it('replaces a previous render instead of duplicating markup or inputs', () => {
    const widget = buildWidget();
    renderMath(widget, PAYLOAD);
    renderMath(widget, {
      ...PAYLOAD,
      question: '2 + 2',
      questionImage: 'data:image/png;base64,bbbb',
      salt: 'fresh-salt?expires=9999999999&form=login&v=1',
    });

    expect(widget.querySelectorAll('.nxc-widget__math-js')).toHaveLength(1);
    expect(widget.querySelectorAll('input[name="nxc_math_salt"]')).toHaveLength(1);
    expect(hiddenValue(widget, 'nxc_math_salt')).toBe('fresh-salt?expires=9999999999&form=login&v=1');
    expect(questionImage(widget).alt).toContain('2 + 2');
    expect(questionImage(widget).src).toBe('data:image/png;base64,bbbb');
  });
});
