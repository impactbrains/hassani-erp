import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** Hassani ERP design tokens: khata ink, haldi, ledger red/green on cool paper. */
/** @type {import('tailwindcss').Config} */
export default {
    content: ['./resources/views/**/*.blade.php', './resources/js/**/*.{vue,ts}'],
    theme: {
        extend: {
            fontFamily: {
                sans: ['"IBM Plex Sans"', ...defaultTheme.fontFamily.sans],
                urdu: ['"IBM Plex Sans"', '"Noto Nastaliq Urdu"', 'serif'],
            },
            colors: {
                ink: { 50: '#EEF1F6', 100: '#D9DFEA', 200: '#B3BED3', 300: '#8496B5', 400: '#5A6E93', 500: '#3E5276', 600: '#2D3F60', 700: '#243453', 800: '#1C2B4A', 900: '#141F37', 950: '#0D1527' },
                haldi: { 50: '#FDF7E6', 100: '#FAEBC0', 200: '#F5D889', 300: '#EDC24F', 400: '#E6B02C', 500: '#E0A419', 600: '#BF8710', 700: '#976A10', 800: '#6E4D0E', 900: '#4A340B' },
                ledger: { red: '#C8402F', redSoft: '#FBEAE7', green: '#1E7A55', greenSoft: '#E4F3EC' },
                paper: '#F2F4F6',
                graphite: '#1A1F29',
            },
            borderRadius: { DEFAULT: '6px', lg: '8px', xl: '12px' },
            boxShadow: { panel: '0 1px 0 rgba(20,31,55,.06), 0 1px 3px rgba(20,31,55,.06)', pop: '0 12px 32px -8px rgba(20,31,55,.28)' },
        },
    },
    plugins: [forms],
};
