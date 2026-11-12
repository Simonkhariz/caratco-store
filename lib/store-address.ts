/**
 * The store's address on one line. The street address in Settings often already names the city
 * and state, so they are only added when it does not end a part with them.
 */
export function storeAddressLine(address?: string | null, city?: string | null, state?: string | null): string {
  const parts = (address ?? '').split(',').map((p) => p.trim()).filter(Boolean);
  const alreadyNamed = (name: string) => {
    const wanted = name.toLowerCase();
    return parts.some((part) => {
      const text = part.toLowerCase().replace(/\s+state$/, '');
      return text === wanted || text.endsWith(` ${wanted}`);
    });
  };
  for (const name of [city, state]) {
    const clean = (name ?? '').trim();
    if (clean && !alreadyNamed(clean)) parts.push(clean);
  }
  return parts.join(', ');
}
