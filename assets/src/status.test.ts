import { beforeEach, describe, expect, it } from 'vitest';
import { setStatus } from './status';

function buildWidget(): HTMLElement {
  document.body.innerHTML = `
    <div class="nxc-widget">
      <div class="nxc-widget__status">
        <span class="nxc-widget__spinner"></span>
        <span class="nxc-widget__status-text"></span>
      </div>
    </div>
  `;
  return document.querySelector('.nxc-widget') as HTMLElement;
}

describe('setStatus', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  it('sets the status text and defaults to the info state', () => {
    const widget = buildWidget();

    setStatus(widget, 'Drag the puzzle piece into the gap, then submit the form.');

    const status = widget.querySelector('.nxc-widget__status') as HTMLElement;
    expect(status.querySelector('.nxc-widget__status-text')?.textContent).toBe(
      'Drag the puzzle piece into the gap, then submit the form.',
    );
    expect(status.dataset.state).toBe('info');
  });

  it('records the given state on data-state, for widget.css\'s spinner/icon hook', () => {
    const widget = buildWidget();

    setStatus(widget, 'Verified.', 'verified');

    expect((widget.querySelector('.nxc-widget__status') as HTMLElement).dataset.state).toBe('verified');
  });

  it('does nothing if the widget has no status element', () => {
    document.body.innerHTML = '<div class="nxc-widget"></div>';
    const widget = document.querySelector('.nxc-widget') as HTMLElement;

    expect(() => setStatus(widget, 'hi', 'error')).not.toThrow();
  });
});
