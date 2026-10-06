import typescript from '@rollup/plugin-typescript';

export default {
    input: './js-src/Connector.ts',
    output: [
        { file: './dist/laravel-echo-api-gateway.js', format: 'esm' },
        { file: './dist/laravel-echo-api-gateway.common.js', format: 'cjs', exports: 'named' },
        { file: './dist/laravel-echo-api-gateway.iife.js', format: 'iife', name: 'LaravelEchoApiGateway', exports: 'named' },
    ],
    plugins: [
        // Declarations are emitted by `npm run declarations`.
        typescript({ tsconfig: './tsconfig.json', declaration: false, declarationDir: undefined, outDir: undefined }),
    ],
};
