const js = require("@eslint/js");
const globals = require("globals");

module.exports = [
    {
        ignores: [
            "node_modules/**",
            "dist/**",
            "build/**",
            "coverage/**",
            "CODE/PHP/PHPMailer/**",
            "**/*.min.js"
        ]
    },
    js.configs.recommended,
    {
        files: ["CODE/JS/**/*.js"],
        languageOptions: {
            ecmaVersion: "latest",
            sourceType: "script",
            globals: {
                ...globals.browser,
                ...globals.node,
                bootstrap: "readonly",
                Chart: "readonly",
                ChartDataLabels: "readonly"
            }
        },
        rules: {
            "no-console": "off",
            "no-unused-vars": [
                "warn",
                {
                    argsIgnorePattern: "^_",
                    varsIgnorePattern: "^_",
                    caughtErrors: "none"
                }
            ],
            "no-shadow": "warn",
            eqeqeq: ["error", "always"],
            curly: ["error", "all"],
            semi: ["error", "always"],
            quotes: ["error", "double", { avoidEscape: true, allowTemplateLiterals: true }],
            "no-extra-semi": "error",
            "prefer-const": "error",
            "no-empty": ["error", { allowEmptyCatch: true }]
        }
    }
];
