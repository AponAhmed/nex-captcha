import * as esbuild from 'esbuild';

const watch = process.argv.includes('--watch');

const entryPoints = {
  widget: 'assets/src/widget.ts',
  worker: 'assets/src/worker.ts',
  admin: 'assets/src/admin.ts',
};

const options = {
  entryPoints: Object.values(entryPoints),
  outdir: 'assets/build',
  bundle: true,
  minify: !watch,
  sourcemap: watch,
  target: ['es2020'],
  format: 'iife',
  logLevel: 'info',
};

if (watch) {
  const ctx = await esbuild.context(options);
  await ctx.watch();
} else {
  await esbuild.build(options);
}
