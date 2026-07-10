import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
                display: ['Montserrat', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // Brand yellows — primary actions, active states, highlights
                brand: {
                    50: '#FAFAD2',
                    100: '#FFF4B7',
                    200: '#FFFF81',
                    400: '#FCC201',
                    500: '#FFDF00',
                    600: '#E6C900',
                },
                // Navy — sidebar, header, dark surfaces, primary text on light bg
                ink: {
                    900: '#0A1A2F', // darkest — sidebar background
                    800: '#0B1F3A', // header / elevated dark surface
                    700: '#102A43', // hover state on dark surfaces, secondary dark
                    600: '#1D3A5F',
                    500: '#33506F',
                },
                // Red — destructive actions and alerts ONLY
                danger: {
                    400: '#C1121F',
                    500: '#B21E35',
                    600: '#A61D24',
                },
            },
            borderRadius: {
                DEFAULT: '0.5rem',
            },
        },
    },

    plugins: [forms],
};