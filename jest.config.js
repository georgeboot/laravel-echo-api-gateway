module.exports = {
    transform: {
        '^.+\\.tsx?$': 'ts-jest',
    },
    moduleFileExtensions: ['ts', 'tsx', 'js', 'jsx', 'json', 'node', 'd.ts'],
    transformIgnorePatterns: [
        'node_modules/(?!(laravel-echo)/)',
    ],
};
