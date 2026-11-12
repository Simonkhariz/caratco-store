import { defineConfig, globalIgnores } from 'eslint/config';
import nextVitals from 'eslint-config-next/core-web-vitals';
import nextTs from 'eslint-config-next/typescript';

// next/core-web-vitals rules, including the React Compiler checks that come with
// eslint-config-next 16 (`next lint` was removed in Next.js 16; `npm run lint` runs ESLint),
// plus the TypeScript rules (no `any`, no unused variables).
export default defineConfig([
  ...nextVitals,
  ...nextTs,
  {
    rules: {
      '@typescript-eslint/no-explicit-any': 'error',
      '@typescript-eslint/no-unused-vars': 'error',
    },
  },
  // kravio-dashboard.vercel.app: a downloaded copy of another site's built bundle, kept locally
  // for reference and git-ignored; it is not this project's code.
  // guide: the client pages, a separate plain-HTML site with its own small script.
  globalIgnores(['.next/**', 'out/**', 'build/**', 'next-env.d.ts', 'backend/**', 'public/**', 'design/**', 'docs/**', 'guide/**', 'kravio-dashboard.vercel.app/**']),
]);
