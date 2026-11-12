/** Where the admin's light/dark choice is kept in this browser (see lib/admin-theme.ts). */
export const ADMIN_THEME_KEY = 'shopco_admin_theme';

/**
 * Runs before the first paint (inline in the admin layout) so a dark admin never flashes light.
 * Kept as a string: it must work before React loads.
 */
export const ADMIN_THEME_BOOT_SCRIPT = `try{var t=localStorage.getItem('${ADMIN_THEME_KEY}');if(t!=='light'&&t!=='dark'){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'}if(t==='dark')document.documentElement.classList.add('admin-dark')}catch(e){}`;
