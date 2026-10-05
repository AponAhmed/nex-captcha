/**
 * Shared by widget.ts, slider.ts and math.ts -- whichever challenge
 * type ends up rendering, "update the status line" means the same
 * thing: set its text and its data-state (widget.css's spinner/icon
 * hook). A standalone module instead of living in widget.ts avoids a
 * circular import, since widget.ts itself imports renderSlider/renderMath
 * from those two files.
 */
export type StatusState = 'pending' | 'verified' | 'error' | 'info';

export function setStatus(widget: HTMLElement, text: string, state: StatusState = 'info'): void {
  const status = widget.querySelector<HTMLElement>('.nxc-widget__status');
  if (!status) {
    return;
  }

  status.dataset.state = state;

  const label = status.querySelector<HTMLElement>('.nxc-widget__status-text');
  if (label) {
    label.textContent = text;
  }
}
