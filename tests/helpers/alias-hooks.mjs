// Lets a test import modules that use the "@/..." path alias from tsconfig.json. Import this
// first, then load the module with a dynamic import().
import { registerHooks } from 'node:module';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const ROOT = new URL('../../', import.meta.url);

registerHooks({
  resolve(specifier, context, nextResolve) {
    if (specifier.startsWith('@/')) {
      const base = new URL(specifier.slice(2), ROOT);
      for (const candidate of [`${base.href}.ts`, `${base.href}/index.ts`]) {
        if (existsSync(fileURLToPath(candidate))) return { url: candidate, shortCircuit: true };
      }
    }
    return nextResolve(specifier, context);
  },
});
