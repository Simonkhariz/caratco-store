import type { CartItem } from '@/types';

/** A line added to the cart before it had finished loading. */
export interface AddedLine {
  line: CartItem;
  quantity: number;
}

/**
 * Put lines added while the cart was loading onto the cart that was loaded, so the load never
 * throws them away. Each addition counts on top of what the loaded cart already holds.
 */
export function withAddedLines(items: CartItem[], added: AddedLine[], maxPerLine: number): CartItem[] {
  const out = [...items];
  for (const { line, quantity } of added) {
    const cap = Math.min(maxPerLine, line.maxStock ?? maxPerLine);
    const index = out.findIndex((item) => item.id === line.id);
    if (index > -1) {
      out[index] = { ...out[index], ...line, quantity: Math.min(cap, out[index].quantity + quantity) };
    } else {
      out.push({ ...line, quantity: Math.min(cap, quantity) });
    }
  }
  return out;
}
