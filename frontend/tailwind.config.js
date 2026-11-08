/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'Segoe UI', 'Roboto', 'sans-serif'],
        mono: ['JetBrains Mono', 'ui-monospace', 'SFMono-Regular', 'Menlo', 'monospace'],
      },
      colors: {
        ink: { 950: '#070b12', 900: '#0b1220', 800: '#111a2b', 700: '#1a2640', 600: '#26355a' },
        lock: { 400: '#34d399', 500: '#10b981', 600: '#059669' },
      },
    },
  },
  plugins: [],
}
