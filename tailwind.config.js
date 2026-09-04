/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/views/**/*.blade.php',
        './resources/js/**/*.{ts,tsx}',
    ],
    theme: {
        extend: {
            colors: {
                ink: '#0B0E1A',
                surface: '#14182B',
                surface2: '#1B2038',
                edge: '#2A2F4A',
                violet: '#7C6FFF',
                teal: '#33E1C9',
                fg: '#EDEFFC',
                muted: '#8E93B8',
            },
            fontFamily: {
                display: ['"Space Grotesk"', 'sans-serif'],
                sans: ['Inter', 'sans-serif'],
            },
            boxShadow: {
                glow: '0 0 60px -10px rgba(124, 111, 255, 0.45)',
            },
        },
    },
    plugins: [],
};
