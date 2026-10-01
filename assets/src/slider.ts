/**
 * Renders the slider puzzle challenge and reports the drag trace to the
 * server for verification.
 *
 * The gap X position is never sent to the client (see
 * Challenge\SliderChallenge's own docblock), so unlike the PoW path this
 * widget can never locally confirm success -- status text stays neutral
 * guidance, not a "Verified." claim the server might contradict.
 */

// Mirrors Challenge\SliderChallenge's own constants (src/Challenge/SliderChallenge.php).
const WIDTH = 300;
const PIECE_SIZE = 40;
const KEYBOARD_STEP = 5; // Matches SliderChallenge::TOLERANCE, so a keyboard user can land exactly.
const MAX_LEFT = WIDTH - PIECE_SIZE;

export interface SliderPayload {
  mode: 'slider';
  image: string;
  piece: string;
  pieceY: number;
  salt: string;
  signature: string;
}

interface TracePoint {
  x: number;
  t: number;
}

function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value));
}

/**
 * Removes any slider markup/hidden inputs from a previous render --
 * widget.ts re-invokes renderSlider() on every pre-expiry refresh, and
 * without this, hidden inputs with the same name would pile up.
 */
function clearPreviousRender(widget: HTMLElement): void {
  widget.querySelector('.nxc-widget__slider')?.remove();
  widget.querySelectorAll('input[name^="nxc_slider_"]').forEach((input) => input.remove());
}

function hiddenInput(name: string, value: string): HTMLInputElement {
  const input = document.createElement('input');
  input.type = 'hidden';
  input.name = name;
  input.value = value;
  return input;
}

export function renderSlider(widget: HTMLElement, payload: SliderPayload): void {
  clearPreviousRender(widget);

  const status = widget.querySelector<HTMLElement>('.nxc-widget__status');
  if (status) {
    status.textContent = 'Drag the puzzle piece into the gap, then submit the form.';
  }

  const container = document.createElement('div');
  container.className = 'nxc-widget__slider';

  const background = document.createElement('img');
  background.className = 'nxc-widget__slider-bg';
  background.src = payload.image;
  background.alt = '';
  background.draggable = false;

  const piece = document.createElement('div');
  piece.className = 'nxc-widget__slider-piece';
  piece.tabIndex = 0;
  piece.setAttribute('role', 'slider');
  piece.setAttribute('aria-label', 'Drag to align the puzzle piece with the gap');
  piece.setAttribute('aria-valuemin', '0');
  piece.setAttribute('aria-valuemax', String(MAX_LEFT));
  piece.style.backgroundImage = `url(${payload.piece})`;
  piece.style.top = `${payload.pieceY}px`;

  container.append(background, piece);

  const saltInput = hiddenInput('nxc_slider_salt', payload.salt);
  const signatureInput = hiddenInput('nxc_slider_signature', payload.signature);
  const xInput = hiddenInput('nxc_slider_x', '0');
  const traceInput = hiddenInput('nxc_slider_trace', '[]');

  widget.append(container, saltInput, signatureInput, xInput, traceInput);

  const traceStart = performance.now();
  const trace: TracePoint[] = [];

  const setPosition = (left: number): void => {
    const clamped = clamp(left, 0, MAX_LEFT);
    piece.style.left = `${clamped}px`;
    piece.setAttribute('aria-valuenow', String(clamped));
    xInput.value = String(clamped);
    trace.push({ x: clamped, t: performance.now() - traceStart });
    traceInput.value = JSON.stringify(trace);
  };

  setPosition(0);

  let dragStartLeft = 0;
  let dragStartClientX = 0;

  const onPointerMove = (event: PointerEvent): void => {
    setPosition(dragStartLeft + (event.clientX - dragStartClientX));
  };

  const onPointerUp = (event: PointerEvent): void => {
    piece.removeEventListener('pointermove', onPointerMove);
    piece.removeEventListener('pointerup', onPointerUp);
    piece.releasePointerCapture(event.pointerId);
  };

  piece.addEventListener('pointerdown', (event: PointerEvent) => {
    dragStartLeft = parseFloat(piece.style.left) || 0;
    dragStartClientX = event.clientX;
    piece.setPointerCapture(event.pointerId);
    piece.addEventListener('pointermove', onPointerMove);
    piece.addEventListener('pointerup', onPointerUp);
  });

  piece.addEventListener('keydown', (event: KeyboardEvent) => {
    const current = parseFloat(piece.style.left) || 0;

    if (event.key === 'ArrowLeft') {
      event.preventDefault();
      setPosition(current - KEYBOARD_STEP);
    } else if (event.key === 'ArrowRight') {
      event.preventDefault();
      setPosition(current + KEYBOARD_STEP);
    } else if (event.key === 'Home') {
      event.preventDefault();
      setPosition(0);
    } else if (event.key === 'End') {
      event.preventDefault();
      setPosition(MAX_LEFT);
    }
  });
}
