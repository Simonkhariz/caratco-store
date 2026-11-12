/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./pages/**/*.{js,ts,jsx,tsx,mdx}",
    "./components/**/*.{js,ts,jsx,tsx,mdx}",
    "./app/**/*.{js,ts,jsx,tsx,mdx}",
  ],
  theme: {
    extend: {
      colors: {
        background: "var(--background)",
        foreground: "var(--foreground)",
        // The shop's own colours (backend/config/shop.json, written into the page by app/layout.tsx).
        accent: { DEFAULT: "var(--accent)", hover: "var(--accent-hover)", ink: "var(--accent-ink)" },
        surface: "var(--surface)",
        card: "var(--card)",
        hero: "var(--hero)",
        shop: {
          black: "#000000",
          card: "#F0EEED",
          badge: "#FF3333",
          badgeBg: "rgba(255, 51, 51, 0.1)",
          star: "#FFC633",
          green: "#01AB31",
          gray: {
            50: "#F9FAFB",
            100: "#F0F0F0",
            200: "#E5E7EB",
            300: "#D1D5DB",
            400: "#9CA3AF",
            500: "#6B7280",
            600: "#4B5563",
            700: "#374151",
            800: "#1F2937",
            900: "#111827",
          },
        },
      },
      fontFamily: {
        heading: ["var(--font-heading)", "Impact", "sans-serif"],
        sans: ["var(--font-body)", "-apple-system", "BlinkMacSystemFont", "sans-serif"],
        body: ["var(--font-body)", "sans-serif"],
        integral: ["var(--font-heading)", "Impact", "sans-serif"],
      },
      borderRadius: {
        '20': '20px',
        '40': '40px',
        '62': '62px',
      },
    },
  },
  plugins: [],
};
