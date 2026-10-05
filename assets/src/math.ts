/**
 * Renders the math/text challenge, used for escalation and as the
 * accessible fallback to the slider.
 *
 * The answer is never sent to or checkable by the client (see
 * Challenge\MathChallenge's own docblock, same reasoning as the
 * slider's gap position) -- a human reads the question and answers it
 * themselves, so status text stays neutral instruction, not a
 * "Verified." claim the server might contradict.
 *
 * The question itself ships as a stylized image (questionImage) rather
 * than plain visible text -- cosmetic, not a new security boundary
 * (see MathChallenge's own docblock on why: the plain `question` field
 * is still present and used as the image's alt text, since this mode
 * is itself the accessible fallback and hiding the question from
 * screen readers here would defeat that purpose).
 */

import { setStatus } from './status';

export interface MathPayload {
  mode: 'math';
  question: string;
  questionImage: string;
  salt: string;
  signature: string;
}

/**
 * Removes any math markup/hidden inputs from a previous render -- same
 * reasoning as slider.ts's clearPreviousRender(): widget.ts can
 * re-invoke renderMath() on a pre-expiry refresh.
 */
function clearPreviousRender(widget: HTMLElement): void {
  widget.querySelector('.nxc-widget__math-js')?.remove();
  widget.querySelectorAll('input[name^="nxc_math_"]').forEach((input) => input.remove());
}

function hiddenInput(name: string, value: string): HTMLInputElement {
  const input = document.createElement('input');
  input.type = 'hidden';
  input.name = name;
  input.value = value;
  return input;
}

export function renderMath(widget: HTMLElement, payload: MathPayload): void {
  clearPreviousRender(widget);

  setStatus(widget, 'Answer the question below, then submit the form.', 'info');

  const container = document.createElement('div');
  container.className = 'nxc-widget__math-js';

  const fieldId = `nxc-math-js-${Math.random().toString(36).slice(2)}`;

  const label = document.createElement('label');
  label.htmlFor = fieldId;
  label.textContent = 'Verify you are human — what is shown below?';

  // alt carries the real question -- keeps this accessible to screen
  // readers despite the stylized image (see the module docblock).
  const image = document.createElement('img');
  image.className = 'nxc-widget__math-js-image';
  image.src = payload.questionImage;
  image.alt = `What is ${payload.question}?`;

  // The field Verification\Verifier already dispatches on -- no hidden
  // shadow input, this visible field IS nxc_math_answer, matching the
  // <noscript> fallback's own field naming exactly.
  const answer = document.createElement('input');
  answer.type = 'text';
  answer.inputMode = 'numeric';
  answer.name = 'nxc_math_answer';
  answer.id = fieldId;
  answer.required = true;

  container.append(label, image, answer);

  const saltInput = hiddenInput('nxc_math_salt', payload.salt);
  const signatureInput = hiddenInput('nxc_math_signature', payload.signature);

  widget.append(container, saltInput, signatureInput);
}
