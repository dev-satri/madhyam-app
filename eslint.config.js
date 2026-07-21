import js from '@eslint/js';

export default [
    js.configs.recommended,
    {
        rules: {
            'no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
            'no-console': 'warn'
        },
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: {
                window: 'readonly',
                document: 'readonly',
                console: 'readonly',
                setTimeout: 'readonly',
                Livewire: 'readonly',
                Alpine: 'readonly',
                Sortable: 'readonly',
                Chart: 'readonly',
                wire: 'readonly',
                xdata: 'readonly'
            }
        }
    },
    {
        ignores: ['node_modules/**', 'vendor/**', 'public/build/**']
    }
];
