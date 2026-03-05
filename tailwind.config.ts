import type { Config } from 'tailwindcss';

export default {
  content: ['./app/**/*.{ts,tsx}', './components/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        cloud: '#F7FAFF',
        navy: '#0B1B3A',
        lime: '#B7FF1A'
      }
    }
  },
  plugins: []
} satisfies Config;
